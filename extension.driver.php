<?php

	class extension_db_charsetter extends Extension{

		const FIELD_REGEX		= '/(char)|(text)|(enum)|(set)/';
		const CHARSET_REGEX		= '/(utf8)/';

		public $tables			= array();
		public $table_count		= 0;

		public $fields			= array();
		public $field_count		= 0;

		public $message			= null;
		public $check_message	= null;

		/**
		 * Subscibe to delegates
		 * @return array
		 */
		public function getSubscribedDelegates(){
			return array(
				array(
					'page' => '/system/preferences/',
					'delegate' => 'AddCustomPreferenceFieldsets',
					'callback' => 'appendPreferences'

				)
			);
		}

		/**
		 * Append a fieldset to the preferences page
		 */
		public function appendPreferences($context){

			if($_POST && isset($_POST['action']['check-status']))
			{
				$this->__runCheck();
			}
			else if($_POST && isset($_POST['action']['change-charset']))
			{
				$this->__runCheck();
				$this->__runChange();
			}
			else
			{
				$this->tables = array();
				$this->fields = array();
			}

			$group = new XMLElement('fieldset');
			$group->setAttribute('class', 'settings');
			$group->appendChild(new XMLElement('legend', __('Database Character Set Migration')));

			$div = new XMLElement('div', NULL, array('id' => 'database-action', 'class' => 'label'));
			$span = new XMLElement('span', NULL, array('class' => 'frame'));
			$span->appendChild(new XMLElement('button', __('Check Status'), array('name' => 'action[check-status]', 'type' => 'submit')));
			$span->appendChild(new XMLElement('button', __('Change Character Set'), array('name' => 'action[change-charset]', 'type' => 'submit')));
			$div->appendChild($span);

			$div->appendChild(new XMLElement('p', __('Use this at your own risk. <strong>Backup your database first!</strong>'), array('class' => 'help')));

			if(!is_null($this->check_message))
			{
				$div->appendChild(new XMLElement('p', __($this->check_message)));
			}

			if(!is_null($this->message))
			{
				$div->appendChild(new XMLElement('p', __($this->message)));
			}

			$group->appendChild($div);
			$context['wrapper']->appendChild($group);
		}

		private function __runCheck(){

			Symphony::Log()->writeToLog('Database Character Setter: Running check.', true);
			$this->__retreiveTables();
			$this->__checkFields();

			$this->check_message = "Check complete. " . $this->field_count . " total fields need converting from " . $this->table_count . " tables";

			Symphony::Log()->writeToLog('Database Character Setter: ' . $this->check_message, true);
		}

		private function __runChange(){

			Symphony::Log()->writeToLog('Database Character Setter: Running change.', true);
			$this->__retreiveTables();

			$this->__retreiveFields();

			$this->__convertCharSet('binary');
			$this->__convertDatabase('utf8');
			$this->__convertCharSet('utf8', true);
			$this->__repairFields();
			$this->__optimize();

			$this->message = "Character Set Migration complete. " . $this->table_count . " tables edited, with " . $this->field_count . " fields in total.";

			Symphony::Log()->writeToLog('Database Character Setter: ' . $this->message, true);
		}

		private function __checkFields(){

			if(!empty($this->tables))
			{
				foreach($this->tables as $table)
				{
					$table_columns = Symphony::Database()->fetch('
						SHOW FULL COLUMNS IN ' . $table . '
					');

					$count = 0;

					foreach($table_columns as $row)
					{
						if(preg_match(self::FIELD_REGEX, $row['Type']) && !preg_match(self::CHARSET_REGEX, $row['Collation']))
						{
							$this->field_count++;
							$count++;
						}
					}

					if($count > 0) $this->table_count++;
				}
			}

		}

		private function __retreiveTables(){

			$show_tables = Symphony::Database()->fetch('
				SHOW TABLES
			');

			if(!empty($show_tables))
			{
				foreach($show_tables as $table)
				{
					$this->tables[] = reset($table);
				}
				unset($show_tables);
			}
		}

		private function __retreiveFields(){

			if(!empty($this->tables))
			{
				foreach($this->tables as $table)
				{
					$table_explain = Symphony::Database()->fetch('
						EXPLAIN ' . $table . '
					');

					foreach($table_explain as $row)
					{
						if(preg_match(self::FIELD_REGEX, $row['Type']))
						{
							$fields[$table][$row['Field']] = $row['Type'] . " " . (($row['Null'] == "YES")? "": "NOT ") . "NULL " . ((!is_null($row['Default']))? "DEFAULT '" . $row['Default'] . "'": "");
						}
					}
				}
			}
		}

		private function __convertCharSet($set, $collation = null){
			if(!empty($this->tables))
			{
				foreach($this->tables as $table)
				{
					if(!is_null($collation))
					{
						$sql = 'ALTER TABLE ' . $table . ' CONVERT TO CHARACTER SET ' . $set;

						if(!Symphony::Database()->query($sql))
						{
							Symphony::Log()->writeToLog('Database Character Setter: Change failed on ' . $sql, true);
						}
					}
					elseif($collation == true)
					{
						$sql = 'ALTER TABLE ' . $table . ' CONVERT TO CHARACTER SET ' . $set . ' COLLATE utf8_unicode_ci';

						if(!Symphony::Database()->query($sql))
						{
							Symphony::Log()->writeToLog('Database Character Setter: Change failed on ' . $sql, true);
						}
					}
				}
			}
		}

		private function __convertDatabase($set)
		{
			$dbname = Symphony::Configuration()->get('db', 'database');

			$sql = 'ALTER DATABASE ' . $dbname . ' CHARACTER SET ' . $set . ' COLLATE utf8_unicode_ci';

			if(!Symphony::Database()->query($sql))
			{
				Symphony::Log()->writeToLog('Database Character Setter: Change failed on ' . $sql, true);
			}
		}

		private function __repairFields(){
			if(!empty($this->fields))
			{
				foreach($this->fields as $table => $fields)
				{
					foreach($fields as $field => $options)
					{
						$sql = 'ALTER TABLE ' . $table . ' MODIFY ' . $field . ' ' . $options . ' CHARACTER SET utf8 COLLATION utf8_unicode_ci';

						if(!Symphony::Database()->query($sql))
						{
							Symphony::Log()->writeToLog('Database Character Setter: Change failed on ' . $sql, true);
						}
					}
				}
			}
		}

		private function __optimize()
		{
			if(!empty($tables))
			{
				foreach($tables as $table)
				{
					Symphony::Database()->query('
						OPTIMIZE TABLE ' . $table . '
					');
				}
			}
		}
	}