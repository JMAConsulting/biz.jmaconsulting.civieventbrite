<?php

class CRM_EB_BAO_EventBrite extends CRM_Mailchimp_Sync {

  public static function getResponse($op, $params) {
    $client = new HttpClient('DN5JGJ45R2GDT3JN37EC');
    if ($op == "syncEvents") {
      $eventIds = [];
      // Get organizers for the current user.
      $organizations = $client->get("/users/me/organizations");
      foreach ($organizations['organizations'] as $org) {
        if ($org['_type'] == 'organization') {
          $orgs[] = $org['id'];
        }
      }
      foreach ($orgs as $org) {
        $events = $client->get("/organizations/$org/events");
        if (!empty($events['events'])) {
          foreach ($events['events'] as $event) {
            $eventIds[$event['id']] = $event['name']['text'];
          }
        }
        if ($events['pagination']['has_more_items']) {
          while(true) {
            $events = $client->get('/organizations/' . $org . '/events?continuation=' . $events['pagination']['continuation']);
            if (!empty($events['events'])) {
              foreach ($events['events'] as $event) {
                $eventIds[$event['id']] = $event['name']['text'];
              }
            }
            if (empty($events['pagination']['has_more_items'])) {
              break;
            }
          }
        }
      }
      return $eventIds;
    }
    if ($op == "syncContacts") {
      $ctx = $params['ctx'];
      $event = $params['event'];
      $attendees = $client->get('/events/' . $event . '/attendees');
      if ($attendees['pagination']['has_more_items'] = 1 && $attendees['pagination']['page_count'] > 1) {
        for ($i = 1; $i <= $attendees['pagination']['page_count']; $i++) {
          $multipleAttendees = $client->get('/events/' . $event . '/attendees?page=' . $i);
          $multipleAttendeeGroups[] = $multipleAttendees;
        }
        if (!empty($multipleAttendeeGroups)) {
          foreach ($multipleAttendeeGroups as $group) {
            foreach ($group['attendees'] as $attendee) {
              self::createAttendee($attendee['profile']);
            }
          }
        }
      }
      else {
        foreach ($attendees['attendees'] as $attendee) {
          self::createAttendee($attendee['profile']);
        }
      }
    }
    if ($op == "syncLists") {
      // Get organizers for the current user.
      $organizations = $client->get("/users/me/organizations");
      foreach ($organizations['organizations'] as $org) {
        if ($org['_type'] == 'organization') {
          $orgs[] = $org['id'];
        }
      }
      foreach ($orgs as $org) {
        $lists = $client->get('/organizations/' . $org . '/contact_lists');
        foreach ($lists['contact_lists'] as $list) {
          $contactList[$list['id']] = ['name' => $list['name'], 'user_id' => $list['user_id']];
          $id = CRM_Core_DAO::singleValueQuery("SELECT id FROM civicrm_group WHERE title = %1", [1 => [$list['name'], 'String']]);
          civicrm_api3('Group', 'create', [
            'title' => $list['name'],
            'id' => $id,
            'source' => ts("EventBrite Contact List"),
            'is_active' => TRUE,
            'group_type' => "Mailing List",
            'visibility' => ["User and User Admin Only"],
          ]);
        }
      }
      return $contactList;
    }
    if ($op == "syncContactLists") {
      $ctx = $params['ctx'];
      $list = $params['list'];
      $group = $params['group'];
      $contacts = $client->get('/users/me/contact_lists/' . $list . '/contacts');
      if ($contacts['pagination']['has_more_items'] = 1 && $contacts['pagination']['page_count'] > 1) {
        for ($i = 1; $i <= $contacts['pagination']['page_count']; $i++) {
          $multipleContacts = $client->get('/users/me/contact_lists/' . $list . '/contacts?page=' . $i);
          $multipleContactGroups[] = $multipleContacts;
        }
        if (!empty($multipleContactGroups)) {
          foreach ($multipleContactGroups as $batch) {
            foreach ($batch['contacts'] as $contact) {
              self::createContact($contact, $group);
            }
          }
        }
      }
      else {
        foreach ($contacts['contacts'] as $contact) {
          self::createContact($contact, $group);
        }
      }
    }
  }

  public static function createContact($contact, $group) {
    $params = [
      'contact_type' => 'Individual',
      'first_name' => $contact['first_name'],
      'last_time' => $contact['last_name'],
      'email' => $contact['email'],
    ];
    $dedupeParams = CRM_Dedupe_Finder::formatParams($params, 'Individual');
    $dedupeParams['check_permission'] = FALSE;
    $dupes = CRM_Dedupe_Finder::dupesByParams($dedupeParams, 'Individual');
    $cid = CRM_Utils_Array::value('0', $dupes, NULL);
    if ($cid) {
      $params['contact_id'] = $cid;
    }

    try {
      $contact = civicrm_api3('Contact', 'create', $params);

      // Add to group.
      $groupId = CRM_Core_DAO::singleValueQuery("SELECT id FROM civicrm_group WHERE title = %1", [1 => [$group, 'String']]);
      civicrm_api3('GroupContact', 'create', [
        'group_id' => $groupId,
        'contact_id' => $contact['id'],
        'status' => 'Added',
      ]);
    }
    catch (CiviCRM_API3_Exception $e) {
      // Handle error here.
      $errorMessage = $e->getMessage();
      $errorCode = $e->getErrorCode();
      $errorData = $e->getExtraParams();
      $error = [
        'error_message' => $errorMessage,
        'error_code' => $errorCode,
        'error_data' => $errorData,
      ];
      CRM_Core_Error::debug_var('Error in processing information:', $error);
    }
  }

  public static function createAttendee($attendee) {
    $params = [
      'contact_type' => 'Individual',
      'first_name' => $attendee['first_name'],
      'last_name' => $attendee['last_name'],
      'source' => 'EventBrite Migration',
      'email' => $attendee['email'],
    ];
    $dedupeParams = CRM_Dedupe_Finder::formatParams($params, 'Individual');
    $dedupeParams['check_permission'] = FALSE;
    $dupes = CRM_Dedupe_Finder::dupesByParams($dedupeParams, 'Individual');
    $cid = CRM_Utils_Array::value('0', $dupes, NULL);
    if ($cid) {
      $params['contact_id'] = $cid;
    }

    // Add phone if present.
    if (!empty($attendee['home_phone'])) {
      $params['api.Phone.create'][] = [
        'location_type_id' => 'Home',
        'phone' => $attendee['home_phone'],
        'is_primary' => 1,
        'phone_type_id' => 'Phone',
      ];
    }

    // Add address(es) if present.
    if (!empty($attendee['addresses'])) {
      foreach ($attendee['addresses'] as $locationType => $address) {
        $addressParams = [
          'skip_geocode' => 1,
          'city' => $address['city'],
          'street_address' => $address['address_1'],
          'supplemental_address_1' => !empty($address['address_2']) ? $address['address_2'] : NULL,
        ];
        if ($locationType == 'bill') {
          $locationType = 'Billing';
        }
        elseif ($locationType == 'ship') {
          $locationType = 'Other';
        }
        $addressParams['location_type_id'] = ucfirst(strtolower($locationType));
        if (!empty($address['country'])) {
          $addressParams['country'] = CRM_Core_DAO::singleValueQuery("SELECT max(id) from civicrm_country where iso_code = '{$address['country']}'");
        }
        if (!empty($address['country']) && !empty($address['region'])) {
          $state = CRM_Core_DAO::singleValueQuery("SELECT max(id) from civicrm_state_province where abbreviation = '{$address['region']}' AND country_id = {$addressParams['country']}");
        }
        if ($state) {
          $addressParams['state_province_id'] = $state;
        }
        $params['api.Address.create'][] = $addressParams;
      }
    }
    try {
      $contact = civicrm_api3('Contact', 'create', $params);
    }
    catch (CiviCRM_API3_Exception $e) {
      // Handle error here.
      $errorMessage = $e->getMessage();
      $errorCode = $e->getErrorCode();
      $errorData = $e->getExtraParams();
      $error = [
        'error_message' => $errorMessage,
        'error_code' => $errorCode,
        'error_data' => $errorData,
      ];
      CRM_Core_Error::debug_var('Error in processing information:', $error);
    }
  }

  public static function syncEvents($ctx) {
    $response = self::getResponse('syncEvents', ['ctx' => $ctx]);
    return $response;
  }

  public static function syncLists($ctx) {
    $response = self::getResponse('syncLists', ['ctx' => $ctx]);
    return $response;
  }

  public static function syncContactLists($ctx, $list, $group) {
    $response = self::getResponse('syncContactLists', ['ctx' => $ctx, 'list' => $list, 'group' => $group]);
    return $response;
  }

  public static function syncContacts($ctx, $event) {
    $response = self::getResponse('syncContacts', ['ctx' => $ctx, 'event' => $event]);
    return $response;
  }

}
