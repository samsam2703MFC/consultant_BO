-- Umożliwia ręczne archiwizowanie zamówień klienta bez wysyłki.
INSERT IGNORE INTO customer_order_status_type (id, name)
VALUES (5, 'Zarchiwizowane');
