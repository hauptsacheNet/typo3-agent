CREATE TABLE tx_agent_task (
    title varchar(255) DEFAULT '' NOT NULL,
    prompt text,
    status int(11) DEFAULT '0' NOT NULL,
    messages json,
    result text,
    cruser_id int(11) unsigned DEFAULT '0' NOT NULL,
);
