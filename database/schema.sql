-- =========================================================================
-- DINEFLOW DUAL-SCHEMA DATABASE DEFINITION (PHP / SQLITE & POSTGRESQL / MYSQL)
-- OLTP: Operational transaction tables
-- OLAP: Star schema data warehouse for BI and Apriori Analytics
-- =========================================================================

-- 1. OLTP: Users & Staff
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'CASHIER',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 2. OLTP: Dining Tables (15-20 tables)
CREATE TABLE IF NOT EXISTS dining_tables (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    table_number VARCHAR(20) UNIQUE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'AVAILABLE',
    current_session_id VARCHAR(64) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 3. OLTP: Table Sessions
CREATE TABLE IF NOT EXISTS table_sessions (
    id VARCHAR(64) PRIMARY KEY,
    table_id INTEGER NOT NULL,
    session_token VARCHAR(64) UNIQUE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    guest_count INTEGER DEFAULT 1,
    total_amount DECIMAL(12, 2) DEFAULT 0.00,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    closed_at DATETIME NULL,
    is_etl_processed INTEGER DEFAULT 0,
    FOREIGN KEY (table_id) REFERENCES dining_tables(id)
);

-- 4. OLTP: Categories
CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(100) NOT NULL,
    display_order INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 5. OLTP: Menu Items
CREATE TABLE IF NOT EXISTS menu_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    price DECIMAL(12, 2) NOT NULL,
    is_available INTEGER DEFAULT 1,
    is_hot INTEGER DEFAULT 0,
    image_url VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id)
);

-- 6. OLTP: Toppings
CREATE TABLE IF NOT EXISTS toppings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(100) NOT NULL,
    price DECIMAL(12, 2) NOT NULL,
    is_available INTEGER DEFAULT 1
);

-- 7. OLTP: Orders
CREATE TABLE IF NOT EXISTS orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id VARCHAR(64) NOT NULL,
    order_code VARCHAR(50) UNIQUE NOT NULL,
    status VARCHAR(20) DEFAULT 'CONFIRMED',
    total_amount DECIMAL(12, 2) NOT NULL,
    idempotency_key VARCHAR(64) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES table_sessions(id)
);

-- 8. OLTP: Order Items (KDS items)
CREATE TABLE IF NOT EXISTS order_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL,
    menu_item_id INTEGER NOT NULL,
    item_name VARCHAR(150) NOT NULL,
    unit_price DECIMAL(12, 2) NOT NULL,
    quantity INTEGER NOT NULL,
    subtotal DECIMAL(12, 2) NOT NULL,
    status VARCHAR(20) DEFAULT 'PENDING',
    chef_id INTEGER NULL,
    notes TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id)
);

-- 9. OLTP: Bill Payments
CREATE TABLE IF NOT EXISTS bill_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id VARCHAR(64) NOT NULL,
    table_id INTEGER NOT NULL,
    subtotal_amount DECIMAL(12, 2) NOT NULL,
    vat_amount DECIMAL(12, 2) NOT NULL,
    service_fee DECIMAL(12, 2) DEFAULT 0.00,
    final_amount DECIMAL(12, 2) NOT NULL,
    payment_method VARCHAR(30) DEFAULT 'CASH',
    cashier_id INTEGER NULL,
    payment_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    invoice_code VARCHAR(50) UNIQUE NOT NULL,
    FOREIGN KEY (session_id) REFERENCES table_sessions(id)
);

-- 10. OLTP: Combo Recommendation Rules (Apriori Overrides)
CREATE TABLE IF NOT EXISTS combo_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_item_id INTEGER NOT NULL,
    target_item_id INTEGER NOT NULL,
    confidence REAL DEFAULT 0.75,
    discount_percent INTEGER DEFAULT 10,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (source_item_id) REFERENCES menu_items(id),
    FOREIGN KEY (target_item_id) REFERENCES menu_items(id)
);

-- =========================================================================
-- OLAP: STAR SCHEMA (DIMENSIONS & FACTS)
-- =========================================================================

-- 11. OLAP: Dim Time
CREATE TABLE IF NOT EXISTS dim_time (
    time_key INTEGER PRIMARY KEY,
    full_date DATE NOT NULL,
    hour INTEGER NOT NULL,
    day_of_week INTEGER NOT NULL,
    is_weekend INTEGER NOT NULL DEFAULT 0
);

-- 12. OLAP: Dim Menu Item
CREATE TABLE IF NOT EXISTS dim_menu_item (
    item_key INTEGER PRIMARY KEY AUTOINCREMENT,
    oltp_item_id INTEGER UNIQUE NOT NULL,
    item_name VARCHAR(150) NOT NULL,
    category_name VARCHAR(100) NOT NULL,
    current_price DECIMAL(12, 2) NOT NULL
);

-- 13. OLAP: Dim Chef
CREATE TABLE IF NOT EXISTS dim_chef (
    chef_key INTEGER PRIMARY KEY AUTOINCREMENT,
    chef_username VARCHAR(50) UNIQUE NOT NULL,
    chef_full_name VARCHAR(100) NOT NULL
);

-- 14. OLAP: Fact Order Items
CREATE TABLE IF NOT EXISTS fact_order_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    time_key INTEGER NOT NULL,
    item_key INTEGER NOT NULL,
    chef_key INTEGER NULL,
    session_id VARCHAR(64) NOT NULL,
    quantity INTEGER NOT NULL,
    revenue DECIMAL(12, 2) NOT NULL,
    prep_time_seconds INTEGER NOT NULL DEFAULT 0,
    is_sla_violated INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (time_key) REFERENCES dim_time(time_key),
    FOREIGN KEY (item_key) REFERENCES dim_menu_item(item_key),
    FOREIGN KEY (chef_key) REFERENCES dim_chef(chef_key)
);

-- 15. OLAP: Fact Clickstream
CREATE TABLE IF NOT EXISTS fact_clickstream (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    time_key INTEGER NOT NULL,
    session_id VARCHAR(64) NOT NULL,
    oltp_item_id INTEGER NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (time_key) REFERENCES dim_time(time_key)
);
