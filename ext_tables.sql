CREATE TABLE tx_agent_task (
    title varchar(255) DEFAULT '' NOT NULL,
    prompt text,
    status int(11) DEFAULT '0' NOT NULL,
    messages json,
    result text,
    cruser_id int(11) unsigned DEFAULT '0' NOT NULL,
    context_table varchar(255) DEFAULT '' NOT NULL,
    context_uid int(11) unsigned DEFAULT '0' NOT NULL,
    return_url text,
    workspace_id int(11) unsigned DEFAULT '0' NOT NULL,
);

CREATE TABLE tx_agent_task_change (
    task_uid int(11) unsigned DEFAULT '0' NOT NULL,
    tablename varchar(255) DEFAULT '' NOT NULL,
    record_uid int(11) unsigned DEFAULT '0' NOT NULL,
    workspace_record_uid int(11) unsigned DEFAULT '0' NOT NULL,
    page_id int(11) unsigned DEFAULT '0' NOT NULL,
    workspace_page_id int(11) unsigned DEFAULT '0' NOT NULL,
);
