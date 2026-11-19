-- [319] SMSAPI : dwie platformy (konto międzynarodowe api.smsapi.com, konto polskie api.smsapi.pl). Token z jednej jest
-- odrzucany przez drugą (błąd 101) — przy zapisie tokenu ERP sprawdza obie i zapamiętuje właściwą w sms.base.
INSERT IGNORE INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('sms.bases', 'https://api.smsapi.com https://api.smsapi.pl', 'https://api.smsapi.com https://api.smsapi.pl', 'string', NULL, 'Platformy SMSAPI sprawdzane przy zapisie tokenu (oddzielone spacją)', 'Plateformes SMSAPI essayées à l''enregistrement du jeton (séparées par une espace)');
