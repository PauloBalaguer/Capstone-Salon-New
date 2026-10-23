CREATE TABLE IF NOT EXISTS admin_notification_reads (
    user_id INT NOT NULL,
    notification_id INT NOT NULL,
    read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, notification_id)
) ENGINE=InnoDB;
