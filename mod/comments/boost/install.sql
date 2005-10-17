CREATE TABLE comments_items (
  id int NOT NULL default '0',
  thread_id int NOT NULL default '0',
  parent int NOT NULL default '0',
  author_ip varchar(15) NOT NULL default '',
  author_id int NOT NULL default '0',
  subject varchar(100) NOT NULL default '',
  entry text NOT NULL,
  edit_author varchar(50) default NULL,
  create_time int NOT NULL default '0',
  edit_time int NOT NULL default '0',
  edit_reason varchar(255) default NULL,
  PRIMARY KEY  (id)
);

CREATE TABLE comments_threads (
  id int NOT NULL default '0',
  key_id int NOT NULL default '0',
  total_comments int NOT NULL default '0',
  last_poster varchar(40) default NULL,
  PRIMARY KEY  (id)
);

CREATE TABLE comments_users (
  user_id int NOT NULL default '0',
  display_name varchar(50) NOT NULL default '',
  signature varchar(255) default NULL,
  comments_made int NOT NULL default '0',
  joined_date int NOT NULL default '0',
  picture varchar(255) default NULL,
  contact_email varchar(255) default NULL,
  website varchar(255) default NULL,
  location varchar(128) default NULL,
  locked smallint(6) NOT NULL default '0'
);
