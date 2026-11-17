CREATE TABLE IF NOT EXISTS parking_bays (
  zone_id INT UNSIGNED NOT NULL,
  bay_number SMALLINT UNSIGNED NOT NULL,
  status ENUM('occupied','booked','unavailable') NOT NULL,
  student_id INT UNSIGNED,
  booking_label VARCHAR(100),
  PRIMARY KEY (zone_id, bay_number),
  UNIQUE KEY one_bay_per_student (student_id),
  FOREIGN KEY (zone_id) REFERENCES parking_zones(id),
  FOREIGN KEY (student_id) REFERENCES users(id),
  CHECK (bay_number BETWEEN 1 AND 9999),
  CHECK ((status = 'occupied') = (student_id IS NOT NULL)),
  CHECK ((status = 'booked') = (booking_label IS NOT NULL))
) ENGINE=InnoDB;
