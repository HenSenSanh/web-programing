-- Run once against an existing database after checking that non-NULL keys are not duplicated.
CREATE UNIQUE INDEX uq_orders_idempotency_key
ON orders (idempotency_key);
