-- Faktury zakupowe ze skanu albo zdjęcia : tekst odczytany OCR w przeglądarce (Tesseract.js), linie rozpoznane jak
-- w PDF i do sprawdzenia ręcznie. Plik zapisany jak przyszedł (zdjęcie JPEG zmniejszone w przeglądarce albo PDF skanu).

ALTER TABLE tfb_inv MODIFY source ENUM('ksef','xml','pdf','manual','ocr') NOT NULL;
