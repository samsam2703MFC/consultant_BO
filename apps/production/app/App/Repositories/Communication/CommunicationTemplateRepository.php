<?php
namespace App\Repositories\Communication;

use App\Core\Helpers\BaseRepository;
use App\Exceptions\CustomException;

class CommunicationTemplateRepository extends BaseRepository
{
    protected string $table = 'communication_template';
    protected $allowedColumns = ['template_type','language_code','name','subject_template','body_template','version','is_active','is_default','created_by'];

    public function getDefault(string $type, string $language): array
    {
        $rows = $this->query('SELECT * FROM communication_template WHERE template_type = :type AND language_code = :language AND is_active = 1 ORDER BY is_default DESC, version DESC LIMIT 1', ['type' => $type, 'language' => $language]);
        return $rows[0] ?? [];
    }

    public function getAllForManagement(): array
    {
        return $this->query('SELECT * FROM communication_template ORDER BY template_type, language_code, is_default DESC, version DESC');
    }

    public function getActiveForType(string $type): array
    {
        return $this->query(
            'SELECT * FROM communication_template WHERE template_type = :type AND is_active = 1 ORDER BY language_code, is_default DESC, version DESC',
            ['type' => $type]
        );
    }

    /**
     * Aktualizacja wykonywana wyłącznie dla istniejącego szablonu.
     * Podbicie wersji zachowuje zgodność z audytem przygotowanych wiadomości.
     */
    public function updateTemplate(int $id, array $data): bool
    {
        if ($this->first(['id' => $id]) === []) {
            throw new CustomException('Wybrany szablon nie istnieje lub został usunięty.', 'errors', 404);
        }

        return $this->executeStatement(
            'UPDATE communication_template
             SET template_type = :template_type,
                 language_code = :language_code,
                 name = :name,
                 subject_template = :subject_template,
                 body_template = :body_template,
                 is_active = :is_active,
                 is_default = :is_default,
                 version = version + 1
             WHERE id = :id',
            [
                'id' => $id,
                'template_type' => $data['template_type'],
                'language_code' => $data['language_code'],
                'name' => $data['name'],
                'subject_template' => $data['subject_template'],
                'body_template' => $data['body_template'],
                'is_active' => (int) $data['is_active'],
                'is_default' => (int) $data['is_default'],
            ]
        );
    }
}
