-- Manual year-level values of the EÜR (no cash flow: AfA, private car use, home office flat rate, ...)
-- amount is always entered in the natural direction of the code (income codes: income, expense codes: expense)

CREATE TABLE llx_eur_manual (
  rowid   integer AUTO_INCREMENT PRIMARY KEY,
  entity  integer DEFAULT 1 NOT NULL,
  year    integer NOT NULL,
  code    varchar(16) NOT NULL,
  amount  double(24,8) DEFAULT 0 NOT NULL,
  note    varchar(255),
  tms     timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
