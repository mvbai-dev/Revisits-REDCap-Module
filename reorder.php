<?php

namespace UNILE\Revisits;

use ExternalModules\ExternalModules;

if($_SERVER['REQUEST_METHOD'] != 'POST'){ // show form as a simple button
    $field_names = $module->getProjectSetting('visitdate-field');
    $field_names_text = implode(',', $field_names);
    echo "<h2>Re-Order visits</h2>";
    if (!$field_names_text) {
        echo "Please configure at least one visit date field in the module configuration first!";
    } else {
        echo "<form method=\"post\">"
           . "<input type=\"hidden\" name=\"redcap_csrf_token\" value=\"" . $module->getCSRFToken() . "\">"
           . "<button>Re-order all visits based on $field_names_text</button></form>";
        echo <<<HTML
				<p><b>READ THIS:</b> This will try to re-order all repeatable events and repeatable instruments based 
				   on the field names above. Instruments/events which contain none of these fields should not be 
           affected. If a repeatable event contains several of these fields, the last one wins (but you should not rely on that).
					 <br>Instances with no value in the date field are sorted to the end, keeping their relative order.
					 <br>The reordering is written to the project Logging page (one entry per record and event),
					 but it still cannot be undone automatically - restoring a backup is the only way back!
					 <br>Existing field history entries of the moved instances are updated to the new instance
					 numbers. Entries that contain nothing but the record ID cannot be assigned to one repeating
					 instrument and keep their old number (see README).</p>
HTML;
    }
} else { // user clicked the button...
    $pid = $module->getProjectId();
    // REDCap spreads project data over several redcap_data* tables (sharding); the table
    // belonging to this project must be looked up at runtime instead of being hard-coded.
    $dataTable = $module->getDataTable($pid);
    $errors = array();
    $affected_rows = array();
    $blank_notices = array();
    $log_notices = array();
    foreach ($module->getProjectSetting('visitdate-field') as $visitdateFieldName) {
       // Only date and datetime fields may be used. REDCap stores those as YYYY-MM-DD[ HH:MM[:SS]]
       // regardless of the display format, so the plain string sort below is chronological.
       // For any other field type the sort would be lexical and therefore meaningless
       // ('10' < '2' < '9'). The config.json field-list is filtered to date fields as well,
       // but a setting stored before that filter existed would not be re-validated.
       $q = $module->query("select element_validation_type from redcap_metadata
                            where project_id = ? and field_name = ?", [$pid, $visitdateFieldName]);
       $validationType = $q->fetch_row()[0] ?? null;
       if ($validationType === null) {
           $errors[] = "$visitdateFieldName: field does not exist in this project - skipped";
           continue;
       }
       if (strpos($validationType, 'date') !== 0) {
           $errors[] = "$visitdateFieldName: not a date or datetime field"
                     . " (validation type '" . ($validationType === '' ? 'none' : $validationType) . "') - skipped";
           continue;
       }

       // All instances that actually exist in the repeating group the date field belongs to,
       // with the date value attached via LEFT JOIN. Enumerating the instances from the date
       // field itself (as this module used to do) silently skips instances where the date is
       // empty - those would keep their old number and collide with a renumbered instance.
       // The repeating group is either a repeating instrument (er.form_name = the date
       // field's form) or a whole repeating event (er.form_name empty; redcap_events_forms
       // is only populated for longitudinal projects, so it may only be used in that branch).
       $scope_sql = "
            select inst.record, inst.event_id, inst.instance, inst.form_name, dv.value as visitdate
            from (
                select distinct rd.record, rd.event_id, rd.instance, nullif(er.form_name, '') as form_name
                from $dataTable rd
                join redcap_metadata md  on md.project_id = rd.project_id and md.field_name = rd.field_name
                join redcap_metadata dfm on dfm.project_id = rd.project_id and dfm.field_name = ?
                join redcap_events_repeat er on er.event_id = rd.event_id
                     and (
                          (nullif(er.form_name, '') is not null
                           and er.form_name = dfm.form_name and md.form_name = er.form_name)
                       or (nullif(er.form_name, '') is null
                           and exists (select 1 from redcap_events_forms ef
                                       where ef.event_id = er.event_id and ef.form_name = dfm.form_name))
                     )
                where rd.project_id = ?
            ) inst
            left join $dataTable dv
                   on dv.project_id = ? and dv.record = inst.record and dv.event_id = inst.event_id
                  and coalesce(dv.instance, -1) = coalesce(inst.instance, -1) and dv.field_name = ?
       ";
       $scope_params = [$visitdateFieldName, $pid, $pid, $visitdateFieldName];

       // The new numbering. Defined once so that the logging query below and the UPDATE
       // can never disagree about what is going to change.
       $numbered_sql = "
            select s.record, s.event_id, s.instance, s.form_name,
             nullif(row_number() over (partition by s.record, s.event_id
                    order by (s.visitdate is null or s.visitdate = ''), s.visitdate,
                             coalesce(s.instance, 1)), 1) as new_instance
            from ($scope_sql) s
       ";

       try {
           // Report how many instances are being sorted to the end for lack of a date.
           $q = $module->query("select count(*) from ($scope_sql) s
                                where s.visitdate is null or s.visitdate = ''", $scope_params);
           $blanks = (int) $q->fetch_row()[0];
           if ($blanks > 0) {
               $blank_notices[] = "$visitdateFieldName: $blanks instance(s) without a date value were sorted to the end";
           }

           // Collect what is about to change, grouped by record and event, so that the
           // reordering can be written to the project log. This has to happen before the
           // UPDATE, because afterwards the old numbering is gone.
           // $moveDetails/$moveFormNames hold the same moves as numbers plus the repeating
           // form (null for a repeating event), for the field-history rewrite further down.
           $moves = array();
           $moveDetails = array();
           $moveFormNames = array();
           $q = $module->query("
                select n.record, n.event_id, n.form_name,
                       coalesce(n.instance, 1) as old_instance,
                       coalesce(n.new_instance, 1) as new_instance
                from ($numbered_sql) n
                where coalesce(n.instance, -1) != coalesce(n.new_instance, -1)
                order by n.record, old_instance
           ", $scope_params);
           while ($row = $q->fetch_assoc()) {
               $groupKey = $row['record'] . "\x1f" . $row['event_id'];
               $moves[$groupKey][] = $row['old_instance'] . " -> " . $row['new_instance'];
               $moveDetails[$groupKey][] = [(int) $row['old_instance'], (int) $row['new_instance']];
               $moveFormNames[$groupKey] = $row['form_name'];
           }

           $query = $module->createQuery();
           $query->add("
            update $dataTable d inner join
            ($numbered_sql)
             new_instances
                on (d.project_id = ? and
                    d.record = new_instances.record and
                    d.event_id = new_instances.event_id and
                    coalesce(d.instance, -1) = coalesce(new_instances.instance, -1))
            inner join redcap_metadata md
            on (md.project_id = d.project_id and md.field_name = d.field_name)
              set d.instance = new_instances.new_instance where
                  coalesce(d.instance, -1) != coalesce(new_instances.new_instance, -1) and
                  (new_instances.form_name is null or new_instances.form_name = md.form_name)
           ", array_merge($scope_params, [$pid]));
           $query->execute();
           // Query::affected_rows calls db_affected_rows() live, so it reflects the most
           // recent query on the connection. It has to be captured before the log writes
           // below, otherwise it would report the number of rows of the last log INSERT.
           $rowsAffected = $query->affected_rows;
           $affected_rows[] = $visitdateFieldName . ": " . $rowsAffected;

           // Project log (redcap_log_event*): one entry per record and event, so the
           // reordering shows up under Logging and in each record's own history.
           foreach ($moves as $groupKey => $instanceMoves) {
               list($record, $eventId) = explode("\x1f", $groupKey, 2);
               \Logging::logEvent(
                   "",                                   // sql_log - no raw SQL, it would be identical for every record
                   $dataTable,                           // object_type
                   "UPDATE",                             // event
                   $record,                              // pk
                   "Repeating instances reordered by '$visitdateFieldName':\n"
                       . implode(", ", $instanceMoves),  // data_values
                   "Reorder repeating instances",        // description (column is varchar(100))
                   "",                                   // change_reason
                   "",                                   // userid_override - keep the acting user
                   $pid,                                 // project_id_override
                   true,                                 // useNOW
                   $eventId                              // event_id_override
               );
           }
           $log_notices[] = $visitdateFieldName . ": " . count($moves)
                          . " record/event group(s) written to the project log";

           // Field history: the Logging page and the per-field history read redcap_log_event*,
           // where the instance is not a column but a text prefix of data_values
           // ("[instance = N],\n", no prefix at all for instance 1). The UPDATE above leaves
           // those rows alone, so they are retagged here to the new numbering.
           //
           // All matching rows are collected first and written afterwards. Rewriting pair by
           // pair would chain within a rotation: a row moved 1 -> 2 would be picked up again
           // by 2 -> 3 and then by 3 -> 1.
           //
           // Only data-entry rows are touched (value changes and file uploads/deletions, which the
           // data history popup reads as well); the module's own "Reorder" entries describe the
           // old numbering on purpose. For a repeating event every form in the event shares one
           // instance axis. For a repeating instrument several independently numbered forms can
           // live in the same event, so a row is only rewritten if its fields belong to the
           // reordered form. record_id is not counted for that - REDCap logs it on record
           // creation regardless of the form - and a row with nothing but record_id ("instance
           // created") cannot be attributed there and stays as it is.
           $logTable = \REDCap::getLogEventTable($pid);
           $fieldForms = array();
           $q = $module->query("select field_name, form_name from redcap_metadata where project_id = ?", [$pid]);
           while ($row = $q->fetch_assoc()) {
               $fieldForms[$row['field_name']] = $row['form_name'];
           }
           $historyUpdates = array();
           $historyUnattributable = 0;
           foreach ($moveDetails as $groupKey => $pairs) {
               list($moveRecord, $moveEventId) = explode("\x1f", $groupKey, 2);
               $scopeFormName = $moveFormNames[$groupKey];
               foreach ($pairs as $pair) {
                   list($oldInstance, $newInstance) = $pair;
                   $tagCondition = ($oldInstance === 1)
                       ? array("not like ?", "[instance = %")
                       : array("like ?", "[instance = $oldInstance],%");
                   $rows = $module->query("
                        select log_event_id, event, description, data_values from $logTable
                        where project_id = ? and pk = ? and event_id = ?
                          and event in ('INSERT', 'UPDATE', 'DELETE', 'DOC_UPLOAD', 'DOC_DELETE')
                          and object_type like 'redcap\_data%'
                          and description <> 'Reorder repeating instances'
                          and data_values {$tagCondition[0]}
                   ", [$pid, $moveRecord, $moveEventId, $tagCondition[1]]);
                   while ($row = $rows->fetch_assoc()) {
                       $body = ($oldInstance === 1)
                           ? $row['data_values']
                           : substr($row['data_values'], strlen("[instance = $oldInstance],\n"));

                       // Checkbox values are logged as "field(code) = checked". A deleted file
                       // upload is logged as the bare field name, without "= value".
                       if ($row['event'] === 'DOC_DELETE') {
                           $loggedFields = array(trim($body));
                       } else {
                           preg_match_all('/^([a-z_][a-z0-9_]*)(?:\([^)]*\))?\s*=/mi', $body, $fieldMatches);
                           $loggedFields = array_unique($fieldMatches[1]);
                       }
                       $knownFields = array_intersect($loggedFields, array_keys($fieldForms));
                       $specificFields = array_diff($knownFields, array('record_id'));
                       if (empty($specificFields)) {
                           // In a repeating event a record_id-only row is the creation of that event
                           // instance. Deleting the whole record is not instance-specific, and in a
                           // repeating instrument the row does not say which form it belongs to.
                           if ($scopeFormName !== null || strpos($row['description'], 'Delete record') === 0) {
                               $historyUnattributable++;
                               continue;
                           }
                       } elseif ($scopeFormName !== null) {
                           $forms = array_unique(array_map(function ($f) use ($fieldForms) {
                               return $fieldForms[$f];
                           }, $specificFields));
                           if (count($forms) > 1) {
                               $historyUnattributable++;
                               continue;
                           }
                           if (reset($forms) !== $scopeFormName) {
                               continue;   // another repeating form with its own numbering
                           }
                       }

                       $newTag = ($newInstance === 1) ? "" : "[instance = $newInstance],\n";
                       $historyUpdates[$row['log_event_id']] = $newTag . $body;
                   }
               }
           }
           foreach ($historyUpdates as $logEventId => $dataValues) {
               $module->query("update $logTable set data_values = ? where log_event_id = ?",
                              [$dataValues, $logEventId]);
           }
           if (!empty($historyUpdates) || $historyUnattributable > 0) {
               $log_notices[] = $visitdateFieldName . ": field history - " . count($historyUpdates)
                              . " log entry/entries updated to the new instance numbers"
                              . ($historyUnattributable > 0
                                    ? ", $historyUnattributable left unchanged (cannot be attributed"
                                      . " to one repeating form)"
                                    : "");
           }

           // Module log: one entry per run and field, for technical traceability.
           $module->log("Reorder repeating instances", array(
               "field"                   => $visitdateFieldName,
               "data_table"              => $dataTable,
               "groups_changed"          => count($moves),
               "rows_affected"           => $rowsAffected,
               "instances_without_date"  => $blanks,
               "history_rows_rewritten"  => count($historyUpdates),
               "history_rows_unattributable" => $historyUnattributable,
           ));
       } catch (\Throwable $e) {
           $errors[] = $visitdateFieldName . ": " . $e->getMessage()
                     . " (if this is a SQL error, check that your MySQL/MariaDB version supports"
                     . " window functions - MySQL 8.0 / MariaDB 10.2 and above)";
       }
    }
    echo "<h2>Re-Order visits</h2>";
    echo "<p>Data table used for this project: <b>$dataTable</b></p>";
    if (!empty($affected_rows)) {
        echo "Numbers of reordered individual fields:<br>" . implode('<br>', $affected_rows);
    }
    if (!empty($log_notices)) {
        echo "<br><br>" . implode('<br>', $log_notices);
    }
    if (!empty($blank_notices)) {
        echo "<br><br>" . implode('<br>', $blank_notices);
    }
    if (!empty($errors)) {
        echo "<br><br>Sorry, some errors occured:<br>" . implode('<br>', $errors);
    }
}

?>
