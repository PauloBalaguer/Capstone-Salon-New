-- Seed every product shown on the landing page's Products tab
-- (PRODUCT_SUGGESTIONS in assets/js/main.js) into each branch's inventory.
--
-- Stock is random between 45 and 95 with reorder_level 10 and max_stock 100,
-- so nothing starts as low stock in any view:
--   admin / staff low stock = stock <= reorder_level (10)
--   cashier "Critical"      = stock <= 20% of max_stock (20)
-- Retail items get a sale price; Professional Use items (salon supplies used
-- during services) have none. Re-running skips products a branch already has.

DROP TEMPORARY TABLE IF EXISTS seed_products;
CREATE TEMPORARY TABLE seed_products (
  product_name VARCHAR(255), category VARCHAR(100), type VARCHAR(20), cost_price DECIMAL(10,2), sale_price DECIMAL(10,2) NULL
);

INSERT INTO seed_products (product_name, category, type, cost_price, sale_price) VALUES
  ('Shampoo',            'Hair Care',               'Retail',           180.00, 299.00),
  ('Conditioner',        'Hair Care',               'Retail',           190.00, 319.00),
  ('Hair Serum',         'Hair Care',               'Retail',           220.00, 379.00),
  ('Hair Spray',         'Hair Care',               'Retail',           160.00, 269.00),
  ('Hair Dye',           'Hair Color & Chemical',   'Professional Use', 250.00, NULL),
  ('Bleach Powder',      'Hair Color & Chemical',   'Professional Use', 320.00, NULL),
  ('Developer',          'Hair Color & Chemical',   'Professional Use', 180.00, NULL),
  ('Rebonding Cream',    'Hair Color & Chemical',   'Professional Use', 450.00, NULL),
  ('Keratin Treatment',  'Hair Treatment',          'Professional Use', 650.00, NULL),
  ('Hair Mask',          'Hair Treatment',          'Retail',           240.00, 399.00),
  ('Hot Oil Treatment',  'Hair Treatment',          'Professional Use', 200.00, NULL),
  ('Nail Polish',        'Nail Care',               'Retail',            60.00, 120.00),
  ('Base Coat',          'Nail Care',               'Retail',            70.00, 140.00),
  ('Top Coat',           'Nail Care',               'Retail',            70.00, 140.00),
  ('Cuticle Oil',        'Nail Care',               'Retail',            80.00, 150.00),
  ('Nail Gems',          'Nail Art',                'Professional Use',  90.00, NULL),
  ('Glitter',            'Nail Art',                'Professional Use',  60.00, NULL),
  ('Stickers',           'Nail Art',                'Professional Use',  40.00, NULL),
  ('Acrylic Powder',     'Nail Art',                'Professional Use', 280.00, NULL),
  ('False Eyelashes',    'Lash',                    'Retail',            90.00, 180.00),
  ('Lash Glue',          'Lash',                    'Professional Use', 150.00, NULL),
  ('Lash Serum',         'Lash',                    'Retail',           350.00, 599.00),
  ('Eyebrow Pencil',     'Brow',                    'Retail',           120.00, 220.00),
  ('Brow Gel',           'Brow',                    'Retail',           150.00, 260.00),
  ('Brow Tint',          'Brow',                    'Professional Use', 300.00, NULL),
  ('Foundation',         'Makeup',                  'Retail',           380.00, 650.00),
  ('Lipstick',           'Makeup',                  'Retail',           180.00, 320.00),
  ('Blush',              'Makeup',                  'Retail',           200.00, 350.00),
  ('Setting Spray',      'Makeup',                  'Retail',           260.00, 450.00),
  ('Facial Cleanser',    'Skin Care',               'Retail',           220.00, 380.00),
  ('Moisturizer',        'Skin Care',               'Retail',           260.00, 450.00),
  ('Sunscreen',          'Skin Care',               'Retail',           280.00, 480.00),
  ('Facial Mask',        'Facial Care',             'Retail',            60.00, 120.00),
  ('Toner',              'Facial Care',             'Retail',           200.00, 350.00),
  ('Facial Scrub',       'Facial Care',             'Retail',           190.00, 330.00),
  ('Hot Wax',            'Waxing',                  'Professional Use', 350.00, NULL),
  ('Cold Wax Strips',    'Waxing',                  'Retail',           150.00, 260.00),
  ('Soothing Gel',       'Waxing',                  'Retail',           170.00, 290.00),
  ('Cotton Balls',       'Salon Consumables',       'Professional Use',  45.00, NULL),
  ('Gloves',             'Salon Consumables',       'Professional Use', 250.00, NULL),
  ('Tissues',            'Salon Consumables',       'Professional Use',  55.00, NULL),
  ('Shower Caps',        'Salon Consumables',       'Professional Use',  90.00, NULL),
  ('Alcohol',            'Cleaning & Sanitization', 'Professional Use',  85.00, NULL),
  ('Disinfectant Spray', 'Cleaning & Sanitization', 'Professional Use', 180.00, NULL),
  ('Tool Sanitizer',     'Cleaning & Sanitization', 'Professional Use', 220.00, NULL);

INSERT INTO inventory (branch_id, product_name, category, quantity_on_hand, reorder_level, max_stock, cost_price, sale_price, type, is_active)
SELECT b.id, p.product_name, p.category, FLOOR(45 + RAND() * 51), 10, 100, p.cost_price, p.sale_price, p.type, 1
FROM branches b
CROSS JOIN seed_products p
WHERE NOT EXISTS (
  SELECT 1 FROM inventory i WHERE i.branch_id = b.id AND i.product_name = p.product_name
);

DROP TEMPORARY TABLE seed_products;

-- Give every item without one an "Item added" history entry, so the
-- inventory History view starts from the opening stock. When an item already
-- has movements, its opening stock is the first movement's previous stock.
INSERT INTO inventory_adjustments (inventory_id, adjustment_type, quantity, reason, previous_stock, new_stock, adjusted_by, created_at)
SELECT i.id, 'create',
       COALESCE(first.previous_stock, i.quantity_on_hand),
       CONCAT('Opening stock: ', i.product_name, ' (landing page product list).'),
       0, COALESCE(first.previous_stock, i.quantity_on_hand), NULL,
       COALESCE(first.created_at - INTERVAL 1 MINUTE, NOW())
FROM inventory i
LEFT JOIN (
  SELECT ia.inventory_id, ia.previous_stock, ia.created_at
  FROM inventory_adjustments ia
  JOIN (SELECT inventory_id, MIN(id) AS first_id FROM inventory_adjustments GROUP BY inventory_id) f ON f.first_id = ia.id
) first ON first.inventory_id = i.id
WHERE NOT EXISTS (SELECT 1 FROM inventory_adjustments c WHERE c.inventory_id = i.id AND c.adjustment_type = 'create');
