const MEZO_SOURCE = 'facebook_instant_form';
const MEZO_DEFAULT_API_URL = 'https://mezoenergy.hu/api/import/facebook-lead';
const MEZO_DEFAULT_MAX_ROWS = 25;

const MEZO_LEAD_COLUMNS = [
  'id',
  'created_time',
  'campaign_name',
  'form_name',
  'hol_van_az_ingatlan?',
  'milyen_munkára_van_szükség?',
  'van_már_beadott_igény_a_szolgáltató_felé?',
  'település?',
  'email',
  'full_name',
  'phone',
  'lead_status',
];

const MEZO_IMPORT_COLUMNS = [
  'mezo_import_status',
  'mezo_customer_id',
  'mezo_work_request_id',
  'mezo_imported_at',
  'mezo_last_attempt_at',
  'mezo_error',
  'mezo_duplicate',
  'mezo_api_response',
  'mezo_notes',
];

function runMezoFacebookLeadImport() {
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(30000)) {
    return;
  }

  try {
    const sheet = SpreadsheetApp.getActiveSheet();
    ensureMezoImportColumns(sheet);

    const props = mezoProperties_();
    const maxRows = Math.min(25, Math.max(1, Number(props.MEZO_MAX_ROWS_PER_RUN || MEZO_DEFAULT_MAX_ROWS)));
    const retryErrors = String(props.MEZO_RETRY_ERRORS || 'false').toLowerCase() === 'true';
    const values = sheet.getDataRange().getValues();

    if (values.length < 2) {
      return;
    }

    const headerMap = mezoHeaderMap_(values[0]);
    let processed = 0;

    for (let index = 1; index < values.length && processed < maxRows; index++) {
      const row = values[index];
      const status = mezoCell_(row, headerMap, 'mezo_import_status');

      if (!mezoShouldProcessStatus_(status, retryErrors)) {
        continue;
      }

      mezoImportRow_(sheet, index + 1, row, headerMap, props);
      processed++;
    }
  } finally {
    lock.releaseLock();
  }
}

function importActiveMezoTestRow() {
  const sheet = SpreadsheetApp.getActiveSheet();
  ensureMezoImportColumns(sheet);

  const rowNumber = sheet.getActiveCell().getRow();
  if (rowNumber < 2) {
    throw new Error('Válassz ki egy adatsort, ne a fejlécet.');
  }

  const values = sheet.getDataRange().getValues();
  const headerMap = mezoHeaderMap_(values[0]);
  const row = values[rowNumber - 1];

  mezoImportRow_(sheet, rowNumber, row, headerMap, mezoProperties_());
}

function ensureMezoImportColumns(sheet) {
  sheet = sheet || SpreadsheetApp.getActiveSheet();
  const lastColumn = Math.max(sheet.getLastColumn(), 1);
  const headers = sheet.getRange(1, 1, 1, lastColumn).getValues()[0].map(String);
  const existing = {};

  headers.forEach(function(header) {
    if (header.trim() !== '') {
      existing[header.trim()] = true;
    }
  });

  const missing = MEZO_IMPORT_COLUMNS.filter(function(column) {
    return !existing[column];
  });

  if (missing.length === 0) {
    return;
  }

  sheet.getRange(1, lastColumn + 1, 1, missing.length).setValues([missing]);
}

function installMezoFiveMinuteTrigger() {
  ScriptApp.getProjectTriggers().forEach(function(trigger) {
    if (trigger.getHandlerFunction() === 'runMezoFacebookLeadImport') {
      ScriptApp.deleteTrigger(trigger);
    }
  });

  ScriptApp.newTrigger('runMezoFacebookLeadImport')
    .timeBased()
    .everyMinutes(5)
    .create();
}

function setupMezoScriptProperties() {
  PropertiesService.getScriptProperties().setProperties({
    MEZO_API_URL: MEZO_DEFAULT_API_URL,
    MEZO_API_TOKEN: 'PASTE_TOKEN_HERE',
    MEZO_MAX_ROWS_PER_RUN: String(MEZO_DEFAULT_MAX_ROWS),
    MEZO_RETRY_ERRORS: 'false',
  }, false);
}

function mezoImportRow_(sheet, rowNumber, row, headerMap, props) {
  const now = new Date();
  const payload = mezoPayloadFromRow_(row, headerMap, rowNumber);
  const statusColumn = mezoColumn_(headerMap, 'mezo_import_status');
  const lastAttemptColumn = mezoColumn_(headerMap, 'mezo_last_attempt_at');

  sheet.getRange(rowNumber, statusColumn).setValue('FOLYAMATBAN');
  sheet.getRange(rowNumber, lastAttemptColumn).setValue(now);

  const result = mezoPostLead_(payload, props);
  mezoWriteImportResult_(sheet, rowNumber, headerMap, result, now);
}

function mezoPayloadFromRow_(row, headerMap, rowNumber) {
  return {
    source: MEZO_SOURCE,
    external_lead_id: mezoCell_(row, headerMap, 'id'),
    created_time: mezoCell_(row, headerMap, 'created_time'),
    campaign_name: mezoCell_(row, headerMap, 'campaign_name'),
    form_name: mezoCell_(row, headerMap, 'form_name'),
    property_location: mezoCell_(row, headerMap, 'hol_van_az_ingatlan?'),
    work_type: mezoCell_(row, headerMap, 'milyen_munkára_van_szükség?'),
    has_existing_utility_request: mezoCell_(row, headerMap, 'van_már_beadott_igény_a_szolgáltató_felé?'),
    city: mezoCell_(row, headerMap, 'település?'),
    email: mezoCell_(row, headerMap, 'email'),
    full_name: mezoCell_(row, headerMap, 'full_name'),
    phone: mezoCell_(row, headerMap, 'phone'),
    lead_status: mezoCell_(row, headerMap, 'lead_status'),
    sheet_row: rowNumber,
  };
}

function mezoPostLead_(payload, props) {
  const token = String(props.MEZO_API_TOKEN || '').trim();
  if (!token || token === 'PASTE_TOKEN_HERE') {
    return {
      ok: false,
      httpStatus: 0,
      body: { status: 'HIBA', error: 'Missing MEZO_API_TOKEN Script Property' },
    };
  }

  const url = String(props.MEZO_API_URL || MEZO_DEFAULT_API_URL).trim();
  let response;
  try {
    response = UrlFetchApp.fetch(url, {
      method: 'post',
      contentType: 'application/json; charset=utf-8',
      headers: {
        Authorization: 'Bearer ' + token,
        Accept: 'application/json',
      },
      payload: JSON.stringify(payload),
      muteHttpExceptions: true,
    });
  } catch (error) {
    return {
      ok: false,
      httpStatus: 0,
      body: { status: 'HIBA', error: String(error && error.message ? error.message : error) },
    };
  }

  const text = response.getContentText() || '';
  let body = {};
  try {
    body = text ? JSON.parse(text) : {};
  } catch (error) {
    body = { status: 'HIBA', error: 'Invalid JSON response', raw: text.slice(0, 500) };
  }

  const httpStatus = response.getResponseCode();
  return {
    ok: httpStatus >= 200 && httpStatus < 300,
    httpStatus: httpStatus,
    body: body,
  };
}

function mezoWriteImportResult_(sheet, rowNumber, headerMap, result, attemptedAt) {
  const body = result.body || {};
  const status = result.ok
    ? String(body.status || 'SIKERES')
    : 'HIBA';
  const importedAt = result.ok && (status === 'SIKERES' || status === 'DUPLIKÁLT') ? new Date() : '';
  const error = result.ok ? '' : String(body.error || ('HTTP ' + result.httpStatus));
  const duplicate = body.duplicate === true || status === 'DUPLIKÁLT';

  const updates = {
    mezo_import_status: status,
    mezo_customer_id: body.customer_id || '',
    mezo_work_request_id: body.work_request_id || '',
    mezo_imported_at: importedAt,
    mezo_last_attempt_at: attemptedAt,
    mezo_error: error,
    mezo_duplicate: duplicate ? 'IGEN' : 'NEM',
    mezo_api_response: JSON.stringify({
      httpStatus: result.httpStatus,
      body: body,
    }).slice(0, 5000),
  };

  Object.keys(updates).forEach(function(column) {
    sheet.getRange(rowNumber, mezoColumn_(headerMap, column)).setValue(updates[column]);
  });
}

function mezoShouldProcessStatus_(status, retryErrors) {
  const value = String(status || '').trim().toUpperCase();

  if (value === '' || value === 'ÚJ') {
    return true;
  }

  if (value === 'HIBA') {
    return retryErrors;
  }

  return false;
}

function mezoProperties_() {
  return PropertiesService.getScriptProperties().getProperties();
}

function mezoHeaderMap_(headers) {
  const map = {};
  headers.forEach(function(header, index) {
    const key = String(header || '').trim();
    if (key !== '') {
      map[key] = index + 1;
    }
  });
  return map;
}

function mezoColumn_(headerMap, columnName) {
  if (!headerMap[columnName]) {
    throw new Error('Hiányzó oszlop: ' + columnName);
  }
  return headerMap[columnName];
}

function mezoCell_(row, headerMap, columnName) {
  const index = headerMap[columnName];
  if (!index) {
    return '';
  }

  const value = row[index - 1];
  if (value instanceof Date) {
    return Utilities.formatDate(value, 'UTC', "yyyy-MM-dd'T'HH:mm:ssZ");
  }

  return String(value || '').trim();
}
