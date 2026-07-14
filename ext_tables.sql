CREATE TABLE tx_agent_task (
    title varchar(255) DEFAULT '' NOT NULL,
    prompt text,
    status int(11) DEFAULT '0' NOT NULL,
    result text,
    cruser_id int(11) unsigned DEFAULT '0' NOT NULL,
    context_table varchar(255) DEFAULT '' NOT NULL,
    context_uid int(11) unsigned DEFAULT '0' NOT NULL,
    return_url text,
    workspace_id int(11) unsigned DEFAULT '0' NOT NULL,
    messages int(11) unsigned DEFAULT '0' NOT NULL,
);

CREATE TABLE tx_agent_message (
    task int(11) unsigned DEFAULT '0' NOT NULL,
    role varchar(16) DEFAULT '' NOT NULL,
    content longtext,
    reasoning longtext,
    tool_calls json,
    tool_call_id varchar(128) DEFAULT '' NOT NULL,
    tool_name varchar(255) DEFAULT '' NOT NULL,
    attachments int(11) unsigned DEFAULT '0' NOT NULL,

    KEY task_sorting (task, sorting)
);

CREATE TABLE tx_agent_instruction (
    title varchar(255) DEFAULT '' NOT NULL,
    description varchar(255) DEFAULT '' NOT NULL,
    instruction text,
    mode varchar(20) DEFAULT 'always' NOT NULL,
);

CREATE TABLE tx_agent_task_change (
    task_uid int(11) unsigned DEFAULT '0' NOT NULL,
    tablename varchar(255) DEFAULT '' NOT NULL,
    record_uid int(11) unsigned DEFAULT '0' NOT NULL,
    workspace_record_uid int(11) unsigned DEFAULT '0' NOT NULL,
    page_id int(11) unsigned DEFAULT '0' NOT NULL,
    workspace_page_id int(11) unsigned DEFAULT '0' NOT NULL,
);
