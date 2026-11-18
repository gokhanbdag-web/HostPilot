ALTER TABLE domain_registrations
    ADD COLUMN registrar_action_id VARCHAR(40) NULL AFTER registrar_order_id;
