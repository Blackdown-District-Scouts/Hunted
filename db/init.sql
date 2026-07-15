-- Schema for the Hunted game tracker. Auto-loaded by MySQL on first container start.

CREATE TABLE IF NOT EXISTS teams (
    id   INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS participants (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    team_id       INT NOT NULL,
    number        INT NOT NULL,               -- e.g. North "1", North "2"
    first_name    VARCHAR(60) NOT NULL,
    last_name     VARCHAR(60) NOT NULL DEFAULT '',
    age           INT NULL,
    photo         VARCHAR(255) NULL,          -- cropped display image under public/uploads, NULL = none
    photo_original VARCHAR(255) NULL,         -- uploaded original, kept so the crop can be re-edited
    crop          VARCHAR(255) NULL,          -- JSON crop rectangle (Cropper.js getData), for re-editing
    captures      INT NOT NULL DEFAULT 0,     -- times caught
    loot          INT NOT NULL DEFAULT 0,     -- loot balls collected
    litter        TINYINT NOT NULL DEFAULT 0, -- 1 = collected their litter (flat bonus)
    discretionary INT NOT NULL DEFAULT 0,     -- bonus/penalty points awarded by an admin
    return_time   VARCHAR(5) NULL,            -- "HH:MM", NULL = not back yet
    disqualified  TINYINT NOT NULL DEFAULT 0, -- 1 = removed from scoring (scores 0), still listed
    dq_reason     VARCHAR(255) NULL,          -- why they were disqualified
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    UNIQUE KEY uq_team_number (team_id, number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS team_groups (
    team_id      INT NOT NULL,
    group_label  CHAR(1) NOT NULL,               -- A-H
    player_count INT NOT NULL DEFAULT 0,
    PRIMARY KEY (team_id, group_label),
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    INDEX idx_group (group_label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Discretionary point awards: many per player, each with its own reason.
-- participants.discretionary is kept in sync as the SUM of these rows.
CREATE TABLE IF NOT EXISTS point_awards (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    participant_id INT NOT NULL,
    points         INT NOT NULL,                  -- signed (+ bonus / − penalty)
    reason         VARCHAR(255) NOT NULL DEFAULT '',
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
    INDEX idx_award_participant (participant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    participant_id INT NULL,
    action         VARCHAR(40) NOT NULL,          -- e.g. checkin, undo_checkin, caught_inc, caught_dec
    detail         JSON NULL,                     -- {"field": {"old": ..., "new": ...}, ...}
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_participant (participant_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
