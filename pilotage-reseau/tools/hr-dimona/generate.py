#!/usr/bin/env python3
"""
Générateur des types Dimona v2 à partir du YAML OpenAPI officiel de l'ONSS.

    python3 -I tools/hr-dimona/generate.py docs/dimona/openapi_dimona_v2.yaml

Produit, sans rien deviner :
  - src/hr/Dimona/Generated/Spec.php : URL de base SIM et PROD (bloc `servers`), URL du jeton
    (securitySchemes.Oauth.flows.clientCredentials.tokenUrl), chemins par operationId, empreinte du YAML ;
  - src/hr/Dimona/Dto/*.php : une classe par schéma objet (allOf aplatis), avec ses contraintes
    (required, pattern, maxLength, minimum, enum…) et la ligne du YAML où le schéma est défini ;
  - tests/hr/fixtures/yaml/*.json : les blocs `example` du YAML, pour les tests d'intégration.

Les fichiers générés ne se modifient pas à la main : on corrige le YAML ou ce script, et on relance.
Dépendance : PyYAML (python3-yaml).
"""
import hashlib
import json
import os
import re
import sys

import yaml

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
DTO_DIR = os.path.join(ROOT, 'src', 'hr', 'Dimona', 'Dto')
GEN_DIR = os.path.join(ROOT, 'src', 'hr', 'Dimona', 'Generated')
FIX_DIR = os.path.join(ROOT, 'tests', 'hr', 'fixtures', 'yaml')

CONSTRAINTS = ('format', 'pattern', 'maxLength', 'minLength', 'minimum', 'maximum', 'enum')


def pascal(name: str) -> str:
    parts = re.split(r'[_\W]+', name)
    return ''.join(p[:1].upper() + p[1:] for p in parts if p)


def php_str(s: str) -> str:
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"


def php_val(v):
    if isinstance(v, bool):
        return 'true' if v else 'false'
    if v is None:
        return 'null'
    if isinstance(v, (int, float)):
        return repr(v)
    if isinstance(v, str):
        return php_str(v)
    if isinstance(v, list):
        return '[' + ', '.join(php_val(x) for x in v) + ']'
    if isinstance(v, dict):
        return '[' + ', '.join(php_str(str(k)) + ' => ' + php_val(x) for k, x in v.items()) + ']'
    raise TypeError(type(v))


class Gen:
    def __init__(self, path: str):
        self.path = path
        self.text = open(path, encoding='utf-8').read()
        self.doc = yaml.safe_load(self.text)
        self.schemas = self.doc['components']['schemas']
        self.lines = self.text.split('\n')

    def line_of(self, name: str) -> int:
        target = '    ' + name + ':'
        for i, l in enumerate(self.lines, 1):
            if l == target:
                return i
        return 0

    def ref_name(self, ref: str) -> str:
        return ref.rsplit('/', 1)[-1]

    def is_object(self, name: str) -> bool:
        s = self.schemas[name]
        return 'allOf' in s or s.get('type') == 'object' or 'properties' in s

    def flatten(self, s: dict):
        """Propriétés et required d'un schéma, allOf aplatis (ordre : parties allOf puis propres)."""
        props, req = {}, []
        for part in s.get('allOf', []):
            if '$ref' in part:
                p, r = self.flatten(self.schemas[self.ref_name(part['$ref'])])
            else:
                p, r = self.flatten(part)
            props.update(p)
            req += r
        props.update(s.get('properties') or {})
        req += s.get('required', [])
        return props, list(dict.fromkeys(req))

    def field_spec(self, v: dict) -> dict:
        if '$ref' in v:
            n = self.ref_name(v['$ref'])
            if self.is_object(n):
                return {'ref': pascal(n)}
            # alias scalaire (Ssin, WorkerType, EnterpriseNumber…) : ses contraintes, plus celles du site d'appel
            base = self.field_spec(self.schemas[n])
            base.update({k: v[k] for k in CONSTRAINTS if k in v})
            base['alias'] = n
            return base
        t = v.get('type')
        if t == 'array':
            return {'type': 'array', 'items': self.field_spec(v.get('items') or {})}
        if t in ('string', 'integer', 'boolean', 'number'):
            spec = {'type': t}
            spec.update({k: v[k] for k in CONSTRAINTS if k in v})
            return spec
        return {'type': 'mixed'}

    def php_type(self, spec: dict) -> str:
        if 'ref' in spec:
            return '?' + spec['ref']
        return {'string': '?string', 'integer': '?int', 'boolean': '?bool', 'number': 'int|float|null',
                'array': '?array', 'mixed': 'mixed'}[spec['type']]

    def spec_php(self, spec: dict) -> str:
        out = {}
        for k, v in spec.items():
            if k == 'ref':
                continue
            if k == 'items':
                continue
            out[k] = v
        parts = []
        if 'ref' in spec:
            parts.append("'ref' => " + spec['ref'] + '::class')
        parts += [php_str(k) + ' => ' + php_val(v) for k, v in out.items()]
        if 'items' in spec:
            parts.append("'items' => " + self.spec_php(spec['items']))
        return '[' + ', '.join(parts) + ']'

    def dto(self, name: str) -> str:
        s = self.schemas[name]
        props, req = self.flatten(s)
        cls = pascal(name)
        desc = (s.get('description') or '').replace('*/', '* /').strip()
        lines = ['<?php', '', 'declare(strict_types=1);', '', 'namespace Hr\\Dimona\\Dto;', '',
                 '/**', f' * Schéma « {name} » — {os.path.relpath(self.path, ROOT)}, ligne {self.line_of(name)}.']
        if desc:
            for chunk in re.findall(r'.{1,100}(?:\s|$)', desc):
                lines.append(' * ' + chunk.strip())
        lines += [' *', ' * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.', ' */',
                  f'final class {cls} extends AbstractDto', '{', f'    public const SCHEMA = {php_str(name)};', '']
        specs = {}
        for p, v in props.items():
            spec = self.field_spec(v)
            specs[p] = spec
            d = (v.get('description') or '').replace('*/', '* /').replace('\n', ' ').strip()
            if d:
                lines.append('    /** ' + d[:160] + ' */')
            t = self.php_type(spec)
            lines.append(f'    public {t} ${p} = null;')
        lines += ['', '    public static function fields(): array', '    {', '        return [']
        for p, spec in specs.items():
            lines.append(f'            {php_str(p)} => {self.spec_php(spec)},')
        lines += ['        ];', '    }', '', '    public static function required(): array', '    {',
                  '        return ' + php_val(req) + ';', '    }', '}', '']
        return '\n'.join(lines)

    def servers(self):
        sim = [s['url'] for s in self.doc['servers'] if re.match(r'^https://services-sim\.', s['url'])]
        prod = [s['url'] for s in self.doc['servers'] if not re.match(r'^https://services-sim\.', s['url'])]
        if len(sim) != 1 or len(prod) != 1:
            sys.exit('servers : il faut exactement une URL de simulation et une de production — ' + repr(self.doc['servers']))
        return sim[0], prod[0]

    def spec_file(self) -> str:
        sim, prod = self.servers()
        token = self.doc['components']['securitySchemes']['Oauth']['flows']['clientCredentials']['tokenUrl']
        ops = {}
        for p, methods in self.doc['paths'].items():
            for m, op in methods.items():
                ops[op['operationId']] = [m.upper(), p]
        sha = hashlib.sha256(self.text.encode('utf-8')).hexdigest()
        rel = os.path.relpath(self.path, ROOT)
        return '\n'.join([
            '<?php', '', 'declare(strict_types=1);', '', 'namespace Hr\\Dimona\\Generated;', '',
            '/**',
            f' * Ce que le YAML OpenAPI Dimona v2 ({rel}) dit des adresses : rien n\'est écrit à la main ici.',
            ' * Les variables d\'environnement HR_DIMONA_* les surchargent (voir Hr\\Support\\Config).',
            ' *',
            ' * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.',
            ' */',
            'final class Spec',
            '{',
            f'    public const SOURCE = {php_str(rel)};',
            f'    public const SHA256 = {php_str(sha)};',
            f'    public const VERSION = {php_str(str(self.doc["info"]["version"]))};',
            '    /** Bloc `servers` du YAML : l\'hôte « services-sim. » est la simulation (doc fonctionnelle v2, § 6). */',
            f'    public const SERVER_SIM = {php_str(sim)};',
            f'    public const SERVER_PROD = {php_str(prod)};',
            '    /** securitySchemes.Oauth.flows.clientCredentials.tokenUrl — la seule URL de jeton que le YAML donne. */',
            f'    public const TOKEN_URL = {php_str(token)};',
            '    /** operationId → [méthode, chemin]. */',
            '    public const OPERATIONS = ' + php_val(ops) + ';',
            '}', ''])

    def run(self):
        os.makedirs(DTO_DIR, exist_ok=True)
        os.makedirs(GEN_DIR, exist_ok=True)
        os.makedirs(FIX_DIR, exist_ok=True)
        written = []
        for name in self.schemas:
            if not self.is_object(name):
                continue
            f = os.path.join(DTO_DIR, pascal(name) + '.php')
            open(f, 'w', encoding='utf-8').write(self.dto(name))
            written.append(f)
            ex = self.schemas[name].get('example')
            if ex is not None:
                open(os.path.join(FIX_DIR, name + '.json'), 'w', encoding='utf-8').write(
                    json.dumps(ex, indent=2, ensure_ascii=False, default=str) + '\n')
        open(os.path.join(GEN_DIR, 'Spec.php'), 'w', encoding='utf-8').write(self.spec_file())
        print(f'{len(written)} DTO, Spec.php, {len(os.listdir(FIX_DIR))} fixtures — YAML {self.path}')


if __name__ == '__main__':
    if len(sys.argv) != 2:
        sys.exit('usage : generate.py chemin/vers/openapi_dimona_v2.yaml')
    Gen(sys.argv[1]).run()
