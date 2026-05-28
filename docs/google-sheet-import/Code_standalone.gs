const MEZO_SOURCE = 'facebook_instant_form';
const MEZO_DEFAULT_API_URL = 'https://mvm-mezoenergy.hu/api/import/facebook-lead';
const MEZO_DEFAULT_SHEET_NAME = 'Munkalap1';
const MEZO_DEFAULT_MAX_ROWS = 25;
const MEZO_ADMIN_TOKEN_PLACEHOLDER = 'IDE_MASOLD_A_KULON_ADMIN_RUN_TOKEN_T';

const MEZO_APPROVED_IMPORT_STATUSES = ['IMPORTALANDO', 'JOVAHAGYVA'];

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
    'igény neve',
    'igeny_neve',
  ],
  property_location: [
    'hol_van_az_ingatlan?',
    'hol_van_az_ingatlan',
    'hol van az ingatlan?',
  ],
  work_type: [
    'milyen_munkára_van_szükség?',
    'milyen_munkara_van_szukseg?',
    'milyen_munkara_van_szukseg',
    'milyen munkára van szükség?',
    'milyen munkara van szukseg?',
  ],
  has_existing_utility_request: [
    'van_már_beadott_igény_a_szolgáltató_felé?',
    'van_mar_beadott_igeny_a_szolgaltato_fele?',
    'van_mar_beadott_igeny_a_szolgaltato_fele',
    'van már beadott igény a szolgáltató felé?',
    'van mar beadott igeny a szolgaltato fele?',
  ],
  city: [
    'település?',
    'telepules?',
    'telepules',
  ],
};

function doPost(e) {
  let request;

  try {
    request = mezoParseWebappRequest_(e);
  } catch (error) {
    return mezoJsonResponse_({
      ok: false,
      status: 'HIBA',
      error: 'Invalid JSON request',
    });
  }

  const action = String(request.action || '').trim().toLowerCase();
  const tokenCheck = mezoValidateAdminRunToken_(request.token);

  if (!tokenCheck.ok) {
    return mezoJsonResponse_(tokenCheck.response);
  }

  try {
    if (action === 'health') {
      return mezoJsonResponse_(mezoHealthResponse_());
    }

    if (action === 'preview') {
      return mezoJsonResponse_(mezoPreviewApprovedLeads_());
    }

    if (action === 'run-approved') {
      return mezoJsonResponse_(mezoRunApprovedLeads_());
    }

    if (action === 'delete-triggers') {
      return mezoJsonResponse_(mezoDeleteTriggersFromWebapp_(request));
    }

    return mezoJsonResponse_({
      ok: false,
      status: 'HIBA',
      error: 'Unknown action',
    });
  } catch (error) {
    return mezoJsonResponse_({
      ok: false,
      status: 'HIBA',
      action: action,
      error: String(error && error.message ? error.message : error),
    });
  }
}

function importPendingLeads() {
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(30000)) {
    return;
  }

  try {
    const sheet = getMezoSheet_();
    ensureMezoImportColumns();

    const props = mezoProperties_();
    const maxRows = mezoMaxRowsPerRun_(props);
    const values = sheet.getDataRange().getValues();

    if (values.length < 2) {
      return;
    }

    const headerMap = mezoHeaderMap_(values[0]);
    let processed = 0;

    for (let index = 1; index < values.length && processed < maxRows; index++) {
      const row = values[index];
      const status = mezoCell_(row, headerMap, 'mezo_import_status');

      if (!mezoShouldProcessStatus_(status)) {
        continue;
      }

      mezoImportRow_(sheet, index + 1, row, headerMap, props);
      processed++;
    }
  } finally {
    lock.releaseLock();
  }
}

function importFirstApprovedMezoTestRow() {
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
      const status = mezoCell_(row, headerMap, 'mezo_import_status');

      if (!mezoShouldProcessStatus_(status)) {
        continue;
      }

      return mezoImportRow_(sheet, index + 1, row, headerMap, mezoProperties_());
    }

    throw new Error('Nem talalhato IMPORTALANDO vagy JOVAHAGYVA statuszu tesztsor.');
  } finally {
    lock.releaseLock();
  }
}

function importFirstNewMezoTestRow() {
  return importFirstApprovedMezoTestRow();
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
  let deleted = 0;
  ScriptApp.getProjectTriggers().forEach(function(trigger) {
    if (trigger.getHandlerFunction() === 'importPendingLeads') {
      ScriptApp.deleteTrigger(trigger);
      deleted++;
    }
  });

  return deleted;
}

function setupMezoStandaloneScriptProperties() {
  PropertiesService.getScriptProperties().setProperties({
    MEZO_SPREADSHEET_ID: 'IDE_MASOLD_A_GOOGLE_SHEET_ID_T',
    MEZO_SHEET_NAME: MEZO_DEFAULT_SHEET_NAME,
    MEZO_API_URL: MEZO_DEFAULT_API_URL,
    MEZO_API_TOKEN: 'IDE_MASOLD_A_64_KARAKTERES_TOKEN_T',
    MEZO_ADMIN_RUN_TOKEN: MEZO_ADMIN_TOKEN_PLACEHOLDER,
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

function mezoHealthResponse_() {
  const props = mezoProperties_();

  return {
    ok: true,
    status: 'OK',
    action: 'health',
    spreadsheetConfigured: String(props.MEZO_SPREADSHEET_ID || '').trim() !== '',
    sheetName: String(props.MEZO_SHEET_NAME || MEZO_DEFAULT_SHEET_NAME).trim() || MEZO_DEFAULT_SHEET_NAME,
    maxRowsPerRun: mezoMaxRowsPerRun_(props),
    timedTriggerMode: 'disabled_by_business_rule',
  };
}

function mezoPreviewApprovedLeads_() {
  const sheet = getMezoSheet_();
  ensureMezoImportColumns();

  const values = sheet.getDataRange().getValues();
  const summary = mezoEmptyPreviewSummary_();
  const previewRows = [];

  if (values.length < 2) {
    return {
      ok: true,
      status: 'OK',
      action: 'preview',
      summary: summary,
      previewRows: previewRows,
      maxRowsPerRun: mezoMaxRowsPerRun_(mezoProperties_()),
    };
  }

  const headerMap = mezoHeaderMap_(values[0]);

  for (let index = 1; index < values.length; index++) {
    const row = values[index];
    const status = mezoCell_(row, headerMap, 'mezo_import_status');
    const normalized = mezoNormalizeStatus_(status);

    summary.totalRows++;

    if (normalized === '') {
      summary.emptyStatus++;
    } else if (MEZO_APPROVED_IMPORT_STATUSES.indexOf(normalized) !== -1) {
      summary.importable++;
      if (previewRows.length < 10) {
        previewRows.push(mezoPreviewRow_(row, headerMap, index + 1));
      }
    } else if (normalized === 'SIKERES') {
      summary.success++;
    } else if (normalized === 'DUPLIKALT') {
      summary.duplicate++;
    } else if (normalized === 'HIBA') {
      summary.error++;
    } else if (normalized === 'NEMIMPORTAL' || normalized === 'ELUTASITVA') {
      summary.notImportedOrRejected++;
    } else if (normalized === 'FOLYAMATBAN') {
      summary.inProgress++;
    } else if (normalized === 'UJ' || normalized === 'ELLENORZESREVAR') {
      summary.waitingReview++;
    } else {
      summary.otherNotAllowed++;
    }
  }

  return {
    ok: true,
    status: 'OK',
    action: 'preview',
    summary: summary,
    previewRows: previewRows,
    maxRowsPerRun: mezoMaxRowsPerRun_(mezoProperties_()),
  };
}

function mezoRunApprovedLeads_() {
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(30000)) {
    return {
      ok: false,
      status: 'HIBA',
      action: 'run-approved',
      error: 'Import mar fut. Probald ujra kesobb.',
    };
  }

  try {
    const sheet = getMezoSheet_();
    ensureMezoImportColumns();

    const props = mezoProperties_();
    const maxRows = mezoMaxRowsPerRun_(props);
    const values = sheet.getDataRange().getValues();
    const summary = {
      totalRows: Math.max(values.length - 1, 0),
      limit: maxRows,
      processed: 0,
      imported: 0,
      duplicated: 0,
      failed: 0,
      skipped: 0,
    };
    const errors = [];

    if (values.length < 2) {
      return {
        ok: true,
        status: 'OK',
        action: 'run-approved',
        summary: summary,
        errors: errors,
      };
    }

    const headerMap = mezoHeaderMap_(values[0]);

    for (let index = 1; index < values.length; index++) {
      const row = values[index];
      const status = mezoCell_(row, headerMap, 'mezo_import_status');

      if (!mezoShouldProcessStatus_(status)) {
        summary.skipped++;
        continue;
      }

      if (summary.processed >= maxRows) {
        summary.skipped++;
        continue;
      }

      try {
        const rowResult = mezoImportRow_(sheet, index + 1, row, headerMap, props);
        summary.processed++;

        if (rowResult.duplicate) {
          summary.duplicated++;
        } else if (rowResult.ok) {
          summary.imported++;
        } else {
          summary.failed++;
          errors.push({
            row: index + 1,
            error: rowResult.error || 'Import hiba',
          });
        }
      } catch (error) {
        summary.processed++;
        summary.failed++;
        const message = String(error && error.message ? error.message : error);
        mezoWriteRowError_(sheet, index + 1, headerMap, message, new Date());
        errors.push({
          row: index + 1,
          error: message,
        });
      }
    }

    return {
      ok: true,
      status: summary.failed > 0 ? 'RESZBEN_SIKERES' : 'OK',
      action: 'run-approved',
      summary: summary,
      errors: errors.slice(0, 10),
    };
  } finally {
    lock.releaseLock();
  }
}

function mezoDeleteTriggersFromWebapp_(request) {
  if (request.confirm_delete_triggers !== true) {
    return {
      ok: false,
      status: 'HIBA',
      action: 'delete-triggers',
      error: 'Missing delete trigger confirmation',
    };
  }

  const deleted = deleteMezoImportTriggers();

  return {
    ok: true,
    status: 'OK',
    action: 'delete-triggers',
    deleted: deleted,
  };
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

  const body = result.body || {};
  const normalizedStatus = mezoNormalizeStatus_(body.status || (result.ok ? 'SIKERES' : 'HIBA'));

  return {
    ok: result.ok,
    duplicate: body.duplicate === true || normalizedStatus === 'DUPLIKALT',
    status: normalizedStatus,
    error: result.ok ? '' : String(body.error || ('HTTP ' + result.httpStatus)),
  };
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

function mezoWriteRowError_(sheet, rowNumber, headerMap, message, attemptedAt) {
  const updates = {
    mezo_import_status: 'HIBA',
    mezo_imported_at: '',
    mezo_last_attempt_at: attemptedAt,
    mezo_error: String(message || 'Import hiba').slice(0, 1000),
    mezo_duplicate: 'NEM',
    mezo_api_response: JSON.stringify({
      httpStatus: 0,
      body: { status: 'HIBA', error: String(message || 'Import hiba').slice(0, 1000) },
    }).slice(0, 5000),
  };

  Object.keys(updates).forEach(function(column) {
    sheet.getRange(rowNumber, mezoColumn_(headerMap, column)).setValue(updates[column]);
  });
}

function mezoShouldProcessStatus_(status) {
  return MEZO_APPROVED_IMPORT_STATUSES.indexOf(mezoNormalizeStatus_(status)) !== -1;
}

function mezoProperties_() {
  return PropertiesService.getScriptProperties().getProperties();
}

function mezoMaxRowsPerRun_(props) {
  const configuredMaxRows = Number(props.MEZO_MAX_ROWS_PER_RUN || MEZO_DEFAULT_MAX_ROWS);

  return Math.min(25, Math.max(1, isNaN(configuredMaxRows) ? MEZO_DEFAULT_MAX_ROWS : configuredMaxRows));
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

function mezoEmptyPreviewSummary_() {
  return {
    totalRows: 0,
    emptyStatus: 0,
    importable: 0,
    success: 0,
    duplicate: 0,
    error: 0,
    notImportedOrRejected: 0,
    waitingReview: 0,
    inProgress: 0,
    otherNotAllowed: 0,
  };
}

function mezoPreviewRow_(row, headerMap, rowNumber) {
  return {
    row: rowNumber,
    city: mezoCell_(row, headerMap, 'city'),
    work_type: mezoCell_(row, headerMap, 'work_type'),
    created_time: mezoCell_(row, headerMap, 'created_time'),
    email_masked: mezoMaskEmail_(mezoCell_(row, headerMap, 'email')),
    phone_masked: mezoMaskPhone_(mezoCell_(row, headerMap, 'phone')),
  };
}

function mezoMaskEmail_(email) {
  const value = String(email || '').trim();
  const parts = value.split('@');
  if (parts.length !== 2 || parts[0] === '' || parts[1] === '') {
    return value === '' ? '' : '***';
  }

  return parts[0].slice(0, 2) + '***@' + parts[1];
}

function mezoMaskPhone_(phone) {
  const digits = String(phone || '').replace(/\D+/g, '');
  if (digits.length <= 4) {
    return digits === '' ? '' : '***';
  }

  return '***' + digits.slice(-4);
}

function mezoParseWebappRequest_(e) {
  const contents = e && e.postData && e.postData.contents ? String(e.postData.contents) : '';
  if (contents.trim() !== '') {
    return JSON.parse(contents);
  }

  return {
    action: e && e.parameter ? e.parameter.action : '',
    token: e && e.parameter ? e.parameter.token : '',
  };
}

function mezoValidateAdminRunToken_(providedToken) {
  const props = mezoProperties_();
  const expectedToken = String(props.MEZO_ADMIN_RUN_TOKEN || '').trim();
  const token = String(providedToken || '').trim();

  if (!expectedToken || expectedToken === MEZO_ADMIN_TOKEN_PLACEHOLDER || expectedToken.length < 32) {
    return {
      ok: false,
      response: {
        ok: false,
        status: 'HIBA',
        error: 'Manual import webapp is not configured',
      },
    };
  }

  if (!mezoConstantTimeEquals_(expectedToken, token)) {
    return {
      ok: false,
      response: {
        ok: false,
        status: 'HIBA',
        error: 'Unauthorized',
      },
    };
  }

  return { ok: true };
}

function mezoConstantTimeEquals_(expected, actual) {
  const left = String(expected || '');
  const right = String(actual || '');
  let diff = left.length ^ right.length;
  const maxLength = Math.max(left.length, right.length);

  for (let index = 0; index < maxLength; index++) {
    diff |= (left.charCodeAt(index) || 0) ^ (right.charCodeAt(index) || 0);
  }

  return diff === 0;
}

function mezoJsonResponse_(payload) {
  return ContentService
    .createTextOutput(JSON.stringify(payload))
    .setMimeType(ContentService.MimeType.JSON);
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
