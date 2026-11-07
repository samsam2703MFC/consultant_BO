-- Audit de la base (#136), tables n_ seulement (les tables d'origine de l'ERP ne sont pas modifiées) :
-- 1. une seule collation, celle de l'ERP (utf8mb4_general_ci) : plus d'« Illegal mix of collations »
--    quand une table n_ est jointe sur du texte à une table d'origine ;
-- 2. un index sur chaque colonne de liaison qui n'en avait pas.
ALTER TABLE n_category_sales_coefficient CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE n_clock_answer CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE n_clock_question CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE n_customer_space CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE n_delivery_notice CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE n_doc_distribution CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE n_doc_distribution_moment CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE n_price_list_state CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE n_product_media CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE n_recipe_producer CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

ALTER TABLE n_clock_answer ADD INDEX idx_n_clock_answer_question (id_question);
ALTER TABLE n_delivery_notice ADD INDEX idx_n_delivery_notice_customer (id_customer), ADD INDEX idx_n_delivery_notice_message (id_message);
ALTER TABLE n_growth_action ADD INDEX idx_n_growth_action_product (id_final_product);
ALTER TABLE n_inv_mapping ADD INDEX idx_n_inv_mapping_supplier (id_supplier);
ALTER TABLE n_inv_supplier ADD INDEX idx_n_inv_supplier_supplier (id_supplier);
ALTER TABLE n_mail_dispatch ADD INDEX idx_n_mail_dispatch_message (id_message);
ALTER TABLE n_mgr_competency_audit ADD INDEX idx_n_mgr_competency_audit_user (assessed_user_id);
ALTER TABLE n_mgr_plan ADD INDEX idx_n_mgr_plan_holder (holder_user_id);
ALTER TABLE n_mp_offer ADD INDEX idx_n_mp_offer_supplier (id_supplier);
ALTER TABLE n_nc ADD INDEX idx_n_nc_product (id_final_product);
ALTER TABLE n_page_view ADD INDEX idx_n_page_view_user (id_user);
ALTER TABLE n_po ADD INDEX idx_n_po_message (id_message);
ALTER TABLE n_position_task ADD INDEX idx_n_position_task_category (id_category);
ALTER TABLE n_product_price_change ADD INDEX idx_n_product_price_change_customer (id_customer);
ALTER TABLE n_prod_audit_log ADD INDEX idx_n_prod_audit_log_entity (entity, entity_id), ADD INDEX idx_n_prod_audit_log_user (id_user);
ALTER TABLE n_prod_bom_loss ADD INDEX idx_n_prod_bom_loss_child (child_id);
ALTER TABLE n_prod_lot_photo ADD INDEX idx_n_prod_lot_photo_device (id_device), ADD INDEX idx_n_prod_lot_photo_employee (id_employee);
ALTER TABLE n_prod_order_demand ADD INDEX idx_n_prod_order_demand_product (id_final_product);
ALTER TABLE n_prod_output ADD INDEX idx_n_prod_output_nc (id_nc);
ALTER TABLE n_prod_output_lot ADD INDEX idx_n_prod_output_lot_employee (id_employee);
ALTER TABLE n_store_product_shop ADD INDEX idx_n_store_product_shop_shop (shop_id);
ALTER TABLE n_task_action ADD INDEX idx_n_task_action_user (id_user);
ALTER TABLE n_tender_item ADD INDEX idx_n_tender_item_material (id_raw_material);
ALTER TABLE n_tender_supplier ADD INDEX idx_n_tender_supplier_supplier (id_supplier), ADD INDEX idx_n_tender_supplier_message (id_message);
