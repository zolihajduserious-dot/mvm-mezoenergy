const MEZO_SOURCE = 'facebook_instant_form';
const MEZO_DEFAULT_API_URL = 'https://mvm-mezoenergy.hu/api/import/facebook-lead';
const MEZO_DEFAULT_SHEET_NAME = 'Munkalap1';
const MEZO_DEFAULT_MAX_ROWS = 25;

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

const MEZO_COLUMN_ALIASES = {
  external_lead_id: ['id'],
  work_request_title: [
    'work_request_title',
    'request_title',
    'adatlap_neve',
    'adatlap neve',
    'munka_neve',
    'munka neve',
    'igeny neve',
    'igeny_neve',
  ],
  property_location: [
    'hol_van_az_ingatlan?',
    'hol_van_az_ingatlan',
    'hol van az ingatlan?',
  ],
  work_type: [
    'milyen_munkara_van_szukseg?',
    'milyen_munkara_van_szukseg',
    'milyen munkara van szukseg?',
  ],
  has_existing_utility_request: [
    'van_mar_beadott_igeny_a_szolgaltato_fele?',
    'van_mar_beadott_igeny_a_szolgaltato_fele',
    'van mar beadott igeny a szolgaltato fele?',
  ],
  city: [
    'telepules?',
    'telepules',
  ],
};

function importPendingLeads() {
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(30000)) {
    return;
  }

  try {
    const sheet = getMezoSheet_();
    ensureMezoImportColumns();

    const props = mezoProperties_();
    const configuredMaxRows = Number(props.MEZO_MAX_ROWS_PER_RUN || MEZO_DEFAULT_MAX_ROWS);
    const maxRows = Math.min(25, Math.max(1, isNaN(configuredMaxRows) ? MEZO_DEFAULT_MAX_ROWS : configuredMaxRows));
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

function importFirstNewMezoTestRow() {
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(30000)) {
    return;
  }

  try {
    const sheet = getMezoSheet_();
    ensureMezoImportColumns();

    const values = sheet.getDataRange().getValues();
    if (values.length < 2) {
      throw new Error('Nincs importalhato adatsor.');
    }

    const headerMap = mezoHeaderMap_(values[0]);
    for (let index = 1; index < values.length; index++) {
      const row = values[index];
      const status = mezoNormalizeStatus_(mezoCell_(row, headerMap, 'mezo_import_status'));

      if (status !== 'UJ') {
        continue;
      }

      mezoImportRow_(sheet, index + 1, row, headerMap, mezoProperties_());
      return;
    }

    throw new Error('Nem talalhato UJ statuszu tesztsor.');
  } finally {
    lock.releaseLock();
  }
}

function ensureMezoImportColumns() {
  const sheet = getMezoSheet_();
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
  deleteMezoImportTriggers();

  ScriptApp.newTrigger('importPendingLeads')
    .timeBased()
    .everyMinutes(5)
    .create();
}

function deleteMezoImportTriggers() {
  ScriptApp.getProjectTriggers().forEach(function(trigger) {
    if (trigger.getHandlerFunction() === 'importPendingLeads') {
      ScriptApp.deleteTrigger(trigger);
    }
  });
}

function setupMezoStandaloneScriptProperties() {
  PropertiesService.getScriptProperties().setProperties({
    MEZO_SPREADSHEET_ID: 'IDE_MASOLD_A_GOOGLE_SHEET_ID_T',
    MEZO_SHEET_NAME: MEZO_DEFAULT_SHEET_NAME,
    MEZO_API_URL: MEZO_DEFAULT_API_URL,
    MEZO_API_TOKEN: 'IDE_MASOLD_A_64_KARAKTERES_TOKEN_T',
    MEZO_MAX_ROWS_PER_RUN: String(MEZO_DEFAULT_MAX_ROWS),
    MEZO_RETRY_ERRORS: 'false',
  }, false);
}

function getMezoSpreadsheet_() {
  const props = mezoProperties_();
  const spreadsheetId = String(props.MEZO_SPREADSHEET_ID || '').trim();

  if (!spreadsheetId || spreadsheetId === 'IDE_MASOLD_A_GOOGLE_SHEET_ID_T') {
    throw new Error('Hianyzik a MEZO_SPREADSHEET_ID Script Property. A Google Sheet URL-ben a /d/ es /edit kozotti reszt masold be.');
  }

  return SpreadsheetApp.openById(spreadsheetId);
}

function getMezoSheet_() {
  const props = mezoProperties_();
  const sheetName = String(props.MEZO_SHEET_NAME || MEZO_DEFAULT_SHEET_NAME).trim() || MEZO_DEFAULT_SHEET_NAME;
  const spreadsheet = getMezoSpreadsheet_();
  const sheet = spreadsheet.getSheetByName(sheetName);

  if (!sheet) {
    throw new Error('Nem talalhato munkalap: ' + sheetName + '. Ellenorizd a MEZO_SHEET_NAME Script Property erteket.');
  }

  return sheet;
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
    external_lead_id: mezoCell_(row, headerMap, 'external_lead_id'),
    created_time: mezoCell_(row, headerMap, 'created_time'),
    campaign_name: mezoCell_(row, headerMap, 'campaign_name'),
    form_name: mezoCell_(row, headerMap, 'form_name'),
    work_request_title: mezoCell_(row, headerMap, 'work_request_title'),
    property_location: mezoCell_(row, headerMap, 'property_location'),
    work_type: mezoCell_(row, headerMap, 'work_type'),
    has_existing_utility_request: mezoCell_(row, headerMap, 'has_existing_utility_request'),
    city: mezoCell_(row, headerMap, 'city'),
    email: mezoCell_(row, headerMap, 'email'),
    full_name: mezoCell_(row, headerMap, 'full_name'),
    phone: mezoCell_(row, headerMap, 'phone'),
    lead_status: mezoCell_(row, headerMap, 'lead_status'),
    sheet_row: rowNumber,
  };
}

function mezoPostLead_(payload, props) {
  const token = String(props.MEZO_API_TOKEN || '').trim();
  if (!token || token === 'IDE_MASOLD_A_64_KARAKTERES_TOKEN_T') {
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
  const normalizedStatus = mezoNormalizeStatus_(status);
  const importedAt = result.ok && (normalizedStatus === 'SIKERES' || normalizedStatus === 'DUPLIKALT') ? new Date() : '';
  const error = result.ok ? '' : String(body.error || ('HTTP ' + result.httpStatus));
  const duplicate = body.duplicate === true || normalizedStatus === 'DUPLIKALT';

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
  const value = mezoNormalizeStatus_(status);

  if (value === '' || value === 'UJ') {
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
  const map = { exact: {}, normalized: {} };
  headers.forEach(function(header, index) {
    const key = String(header || '').trim();
    if (key !== '') {
      map.exact[key] = index + 1;
      map.normalized[mezoNormalizeHeader_(key)] = index + 1;
    }
  });
  return map;
}

function mezoColumn_(headerMap, columnName) {
  const index = mezoColumnOrNull_(headerMap, columnName);
  if (!index) {
    throw new Error('Hianyzo oszlop: ' + columnName);
  }
  return index;
}

function mezoColumnOrNull_(headerMap, columnName) {
  const candidates = [columnName].concat(MEZO_COLUMN_ALIASES[columnName] || []);

  for (let index = 0; index < candidates.length; index++) {
    const candidate = String(candidates[index] || '').trim();
    if (candidate === '') {
      continue;
    }

    if (headerMap.exact && headerMap.exact[candidate]) {
      return headerMap.exact[candidate];
    }

    const normalized = mezoNormalizeHeader_(candidate);
    if (headerMap.normalized && headerMap.normalized[normalized]) {
      return headerMap.normalized[normalized];
    }
  }

  return null;
}

function mezoCell_(row, headerMap, columnName) {
  const index = mezoColumnOrNull_(headerMap, columnName);
  if (!index) {
    return '';
  }

  const value = row[index - 1];
  if (value instanceof Date) {
    return Utilities.formatDate(value, 'UTC', "yyyy-MM-dd'T'HH:mm:ssZ");
  }

  return String(value || '').trim();
}

function mezoNormalizeStatus_(status) {
  return mezoNormalizeText_(status).replace(/_/g, '');
}

function mezoNormalizeHeader_(header) {
  return mezoNormalizeText_(header);
}

function mezoNormalizeText_(value) {
  let text = String(value || '').trim().toLowerCase();
  if (typeof text.normalize === 'function') {
    text = text.normalize('NFD');
  }

  return text
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .replace(/_+/g, '_')
    .toUpperCase();
}
