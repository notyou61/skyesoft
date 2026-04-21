-- ============================================
-- Skyesoft Database Reset Script
-- PRIME LOCATION ID = 1
-- ============================================

SET @PRIME_LOCATION_ID = 1;

-- --------------------------------------------
-- Disable FK checks
-- --------------------------------------------
SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------
-- STEP 1 — Clean Contacts (keep only prime)
-- --------------------------------------------
DELETE FROM tblContacts
WHERE contactLocationId <> @PRIME_LOCATION_ID;

-- --------------------------------------------
-- STEP 2 — Clean Locations (keep only prime)
-- --------------------------------------------
DELETE FROM tblLocations
WHERE locationId <> @PRIME_LOCATION_ID;

-- --------------------------------------------
-- STEP 3 — Clean Entities (keep only those tied to prime location)
-- --------------------------------------------
DELETE FROM tblEntities
WHERE entityId NOT IN (
    SELECT locationEntityId 
    FROM tblLocations 
    WHERE locationId = @PRIME_LOCATION_ID
);

-- --------------------------------------------
-- STEP 4 — Reset Actions (full wipe)
-- --------------------------------------------
TRUNCATE TABLE tblActions;

-- --------------------------------------------
-- STEP 5 — Reset AUTO INCREMENT
-- --------------------------------------------

-- Contacts restart clean
ALTER TABLE tblContacts AUTO_INCREMENT = 1;

-- Locations continue after prime (next = 2)
ALTER TABLE tblLocations AUTO_INCREMENT = 2;

-- Entities continue after remaining entity
SET @NEXT_ENTITY_ID = (
    SELECT entityId + 1 FROM tblEntities LIMIT 1
);

SET @sql = CONCAT('ALTER TABLE tblEntities AUTO_INCREMENT = ', @NEXT_ENTITY_ID);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------
-- Re-enable FK checks
-- --------------------------------------------
SET FOREIGN_KEY_CHECKS = 1;

-- --------------------------------------------
-- COMPLETE
-- --------------------------------------------
SELECT 'Database reset complete. Prime record preserved.' AS status;