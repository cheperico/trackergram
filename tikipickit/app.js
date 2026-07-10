/* ==================================================================
   TikiPickIt — app.js
   Dependencias: IndexedDB nativa, sin frameworks.
   ================================================================== */

/* ─── State ─── */
let _db = null;           // cached IndexedDB connection
let _syncing = false;     // syncAll mutex
let _lastSyncTime = 0;    // timestamp of last syncAll call (rate limit)
const STATE = {
  url: '',          // TikiWiki API base URL
  token: '',        // Bearer token (manual OR OAuth2 access token)
  trackers: [],     // cached list of trackers
  schemas: {},      // { trackerId: fields[] }
  queue: [],        // pending items to sync
  syncedCount: 0,
  currentView: 'settings',
  currentTrackerId: null,
  recentTrackers: [],
  lastTrackerId: null,
  hiddenTrackerIds: new Set(),
  autoSchemas: true,
  startView: 'dashboard',
  online: navigator.onLine,
  oauthMode: false  // true: OAuth2 flow, false: manual token
};

/* ─── OAuth2 State ─── */
const OAUTH = {
  clientId: '',
  clientSecret: '',
  redirectUri: '',
  refreshToken: '',
  expiresAt: 0       // epoch ms when access_token expires
};

/* ─── DOM refs ─── */
const $ = id => document.getElementById(id);
const views = {
  settings: $('view-settings'),
  dashboard: $('view-dashboard'),
  form: $('view-form')
};
const toastEl = $('toast');

/* ==================================================================
   1. INDEXED DB
   ================================================================== */
const DB_NAME = 'tikipickit';
const DB_VER = 1;

function dbOpen() {
  if (_db) return Promise.resolve(_db);
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, DB_VER);
    req.onupgradeneeded = e => {
      const db = e.target.result;
      if (!db.objectStoreNames.contains('trackers'))
        db.createObjectStore('trackers', { keyPath: 'trackerId' });
      if (!db.objectStoreNames.contains('schemas'))
        db.createObjectStore('schemas', { keyPath: 'trackerId' });
      if (!db.objectStoreNames.contains('queue'))
        db.createObjectStore('queue', { keyPath: 'id', autoIncrement: true });
      if (!db.objectStoreNames.contains('synclog'))
        db.createObjectStore('synclog', { keyPath: 'id', autoIncrement: true });
      if (!db.objectStoreNames.contains('prefs'))
        db.createObjectStore('prefs', { keyPath: 'key' });
      if (!db.objectStoreNames.contains('trackerMeta'))
        db.createObjectStore('trackerMeta', { keyPath: 'trackerId' });
    };
    req.onsuccess = () => { _db = req.result; resolve(_db); };
    req.onerror = () => reject(req.error);
  });
}

function dbPut(store, data) {
  return dbOpen().then(db => new Promise((resolve, reject) => {
    const tx = db.transaction(store, 'readwrite');
    const req = tx.objectStore(store).put(data);
    req.onsuccess = () => { data.id = req.result; };
    tx.oncomplete = () => { db.close(); resolve(req.result); };
    tx.onerror = () => { db.close(); reject(tx.error); };
  }));
}

function dbGet(store, key) {
  return dbOpen().then(db => new Promise((resolve, reject) => {
    const tx = db.transaction(store, 'readonly');
    const req = tx.objectStore(store).get(key);
    req.onsuccess = () => { db.close(); resolve(req.result); };
    req.onerror = () => { db.close(); reject(req.error); };
  }));
}

function dbGetAll(store) {
  return dbOpen().then(db => new Promise((resolve, reject) => {
    const tx = db.transaction(store, 'readonly');
    const req = tx.objectStore(store).getAll();
    req.onsuccess = () => { db.close(); resolve(req.result || []); };
    req.onerror = () => { db.close(); reject(req.error); };
  }));
}

function dbDelete(store, key) {
  return dbOpen().then(db => new Promise((resolve, reject) => {
    const tx = db.transaction(store, 'readwrite');
    tx.objectStore(store).delete(key);
    tx.oncomplete = () => { db.close(); resolve(); };
    tx.onerror = () => { db.close(); reject(tx.error); };
  }));
}

function dbClear(store) {
  return dbOpen().then(db => new Promise((resolve, reject) => {
    const tx = db.transaction(store, 'readwrite');
    tx.objectStore(store).clear();
    tx.oncomplete = () => { db.close(); resolve(); };
    tx.onerror = () => { db.close(); reject(tx.error); };
  }));
}

/* ─── Prefs helpers ─── */
function loadPref(key, def) {
  return dbGet('prefs', key).then(r => r ? r.value : def);
}
function savePref(key, value) {
  return dbPut('prefs', { key, value });
}

/* ─── OAuth2 Functions ─── */
function oauth2BuildAuthorizeUrl() {
  // CSPRNG state — 128 bits via crypto.getRandomValues
  const array = new Uint32Array(4);
  crypto.getRandomValues(array);
  const state = btoa(Array.from(array).join('') + '_' + Date.now());
  sessionStorage.setItem('tp_oauth_state', state);
  const params = new URLSearchParams({
    response_type: 'code',
    client_id: OAUTH.clientId,
    redirect_uri: OAUTH.redirectUri,
    scope: 'basic',
    state: state
  });
  return STATE.url.replace(/\/+$/, '') + '/api/oauth/authorize?' + params.toString();
}

function oauth2Login() {
  if (!STATE.url || !OAUTH.clientId || !OAUTH.clientSecret) {
    toast('❌ Completá URL de TikiWiki, Client ID y Client Secret en configuración OAuth2', 'error');
    return;
  }
  if (!isValidTikiUrl(STATE.url)) {
    toast('❌ La URL de TikiWiki debe ser HTTPS', 'error');
    return;
  }
  OAUTH.redirectUri = window.location.origin + window.location.pathname;
  oauth2SaveConfig();
  window.location.href = oauth2BuildAuthorizeUrl();
}

function oauth2HandleCallback() {
  const params = new URLSearchParams(window.location.search);
  const code = params.get('code');
  const state = params.get('state');
  const error = params.get('error');
  if (!code && !error) return false;

  // Clean URL immediately
  const cleanUrl = window.location.origin + window.location.pathname;
  window.history.replaceState({}, document.title, cleanUrl);

  if (error) {
    toast('❌ OAuth2: ' + error, 'error');
    return true;
  }

  // Validate state (CSRF)
  const savedState = sessionStorage.getItem('tp_oauth_state');
  sessionStorage.removeItem('tp_oauth_state');
  if (!savedState || state !== savedState) {
    toast('❌ OAuth2: state mismatch — posible CSRF', 'error');
    return true;
  }

  oauth2ExchangeCode(code);
  return true;
}

function oauth2ExchangeCode(code) {
  const body = new URLSearchParams({
    grant_type: 'authorization_code',
    client_id: OAUTH.clientId,
    client_secret: OAUTH.clientSecret,
    code: code,
    redirect_uri: OAUTH.redirectUri
  });

  const url = STATE.url.replace(/\/+$/, '') + '/api/oauth/access_token';
  fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body.toString()
  }).then(r => {
    if (!r.ok) return r.text().then(t => { throw new Error('HTTP ' + r.status + ': ' + t.slice(0, 200)); });
    return r.json();
  }).then(data => {
    if (data.error) throw new Error(data.error + (data.error_description ? ': ' + data.error_description : ''));
    return oauth2SetTokens(data.access_token, data.refresh_token, data.expires_in);
  }).then(() => {
    toast('✅ Conectado vía OAuth2', 'success');
    STATE.oauthMode = true;
    oauth2SaveConfig();
    return initDashboard();
  }).catch(err => {
    console.error('OAuth2 exchange failed:', err);
    toast('❌ Error al obtener token: ' + err.message, 'error');
  });
}

function oauth2SetTokens(accessToken, refreshToken, expiresIn) {
  STATE.token = accessToken;
  OAUTH.refreshToken = refreshToken || '';
  OAUTH.expiresAt = Date.now() + (parseInt(expiresIn, 10) || 3600) * 1000;
  return Promise.all([
    savePref('oauthAccessToken', accessToken),
    savePref('oauthRefreshToken', OAUTH.refreshToken),
    savePref('oauthExpiresAt', OAUTH.expiresAt)
  ]);
}

function oauth2RefreshToken() {
  if (!OAUTH.refreshToken) return Promise.reject(new Error('No refresh token disponible'));

  const body = new URLSearchParams({
    grant_type: 'refresh_token',
    refresh_token: OAUTH.refreshToken,
    client_id: OAUTH.clientId,
    client_secret: OAUTH.clientSecret
  });

  const url = STATE.url.replace(/\/+$/, '') + '/api/oauth/access_token';
  return fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body.toString()
  }).then(r => {
    if (!r.ok) return r.text().then(t => { throw new Error('HTTP ' + r.status + ': ' + t.slice(0, 200)); });
    return r.json();
  }).then(data => {
    if (data.error) throw new Error(data.error);
    return oauth2SetTokens(data.access_token, data.refresh_token, data.expires_in);
  }).catch(err => {
    console.error('OAuth2 refresh failed:', err);
    // Clear tokens, user must re-login
    return oauth2ClearTokens().then(() => {
      throw new Error('Sesión expirada. Iniciá sesión nuevamente.');
    });
  });
}

function oauth2EnsureValidToken() {
  if (!STATE.oauthMode || !OAUTH.refreshToken) return Promise.resolve();
  // If token still has > 2 min of life, skip refresh
  if (Date.now() < OAUTH.expiresAt - 120000) return Promise.resolve();
  return oauth2RefreshToken();
}

function oauth2ClearTokens() {
  STATE.token = '';
  OAUTH.refreshToken = '';
  OAUTH.expiresAt = 0;
  STATE.oauthMode = false;
  return Promise.all([
    savePref('oauthAccessToken', ''),
    savePref('oauthRefreshToken', ''),
    savePref('oauthExpiresAt', 0),
    savePref('oauthClientId', ''),
    savePref('oauthClientSecret', '')
  ]);
}

function oauth2Logout() {
  if (!STATE.oauthMode) return;
  oauth2ClearTokens().then(() => {
    toast('👋 Sesión OAuth2 cerrada', 'info');
    initSettings();
  });
}

function oauth2SaveConfig() {
  return Promise.all([
    savePref('oauthClientId', OAUTH.clientId),
    savePref('oauthClientSecret', OAUTH.clientSecret),
    savePref('oauthRedirectUri', OAUTH.redirectUri),
    savePref('oauthMode', STATE.oauthMode)
  ]);
}

function oauth2LoadConfig() {
  return Promise.all([
    loadPref('oauthClientId', ''),
    loadPref('oauthClientSecret', ''),
    loadPref('oauthRedirectUri', ''),
    loadPref('oauthMode', false)
  ]).then(([cid, cs, ru, mode]) => {
    OAUTH.clientId = cid;
    OAUTH.clientSecret = cs;
    OAUTH.redirectUri = ru || window.location.origin + window.location.pathname;
    STATE.oauthMode = mode;
    if (mode) {
      // Load OAuth2 tokens
      return Promise.all([
        loadPref('oauthAccessToken', ''),
        loadPref('oauthRefreshToken', ''),
        loadPref('oauthExpiresAt', 0)
      ]).then(([at, rt, exp]) => {
        STATE.token = at;
        OAUTH.refreshToken = rt;
        OAUTH.expiresAt = exp;
      });
    }
  });
}

/* ==================================================================
   2. API — TikiWiki REST calls
   ================================================================== */
function apiUrl(path) {
  const base = STATE.url.replace(/\/+$/, '');
  return base + '/api/' + path.replace(/^\//, '');
}

function apiFetch(path, options, timeoutMs) {
  // If using OAuth2, ensure token is valid before making the call
  if (STATE.oauthMode) {
    return oauth2EnsureValidToken().then(() => _apiFetch(path, options, timeoutMs));
  }
  return _apiFetch(path, options, timeoutMs);
}

function _apiFetch(path, options, timeoutMs) {
  timeoutMs = timeoutMs || 30000;
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  const url = apiUrl(path);
  const headers = { 'Authorization': 'Bearer ' + STATE.token };
  if (options && options.body && !(options.body instanceof FormData)) {
    headers['Content-Type'] = 'application/x-www-form-urlencoded';
  }
  return fetch(url, {
    ...options,
    signal: controller.signal,
    headers: { ...headers, ...(options.headers || {}) }
  }).then(r => {
    clearTimeout(timer);
    if (!r.ok) {
      // Log full response for debugging but show only safe message to user
      return r.text().then(body => {
        console.error('API error', r.status, body.slice(0, 500));
        const msg = r.status === 401 ? 'Token inválido o expirado'
          : r.status === 403 ? 'Permiso denegado'
          : r.status === 404 ? 'Recurso no encontrado'
          : r.status === 429 ? 'Demasiadas solicitudes — esperá un momento'
          : r.status >= 500 ? 'Error del servidor TikiWiki'
          : 'Error HTTP ' + r.status;
        throw new Error(msg);
      });
    }
    return r.json();
  }).catch(err => {
    clearTimeout(timer);
    // Preserve original error for timeouts/network errors
    if (err.name === 'AbortError') throw new Error('La solicitud tardó demasiado — revisá tu conexión');
    throw err;
  });
}

function getTrackers() {
  return apiFetch('trackers').then(data => data.data || data.trackers || []);
}

function getTrackerFields(trackerId) {
  return apiFetch('trackers/' + trackerId + '/fields').then(data => data.fields || []);
}

function createItem(trackerId, fields) {
  const body = new URLSearchParams();
  for (const [k, v] of Object.entries(fields)) {
    if (v !== null && v !== undefined && v !== '') body.append('fields[' + k + ']', String(v));
  }
  return apiFetch('trackers/' + trackerId + '/items', { method: 'POST', body: body.toString() });
}

function uploadFile(galleryId, file) {
  const fd = new FormData();
  fd.append('file', file);
  return apiFetch('galleries/upload?galleryId=' + galleryId, { method: 'POST', body: fd });
}

function testConnection() {
  return apiFetch('trackers');
}

/* ==================================================================
   3. SETTINGS
   ================================================================== */
function showSettings() { switchView('settings'); }
function hideSettings() { switchView('dashboard'); }

function renderWhitelist(trackers) {
  const el = $('s-whitelist');
  if (!trackers.length) { el.innerHTML = '<p style="color:var(--text-muted);font-size:.85rem">Conectá para ver trackers</p>'; return; }
  el.innerHTML = trackers.map(t => `
    <label>
      <input type="checkbox" class="wl-cb" data-id="${t.trackerId}" ${STATE.hiddenTrackerIds.has(t.trackerId) ? '' : 'checked'}>
      ${esc(t.name) || 'Tracker #' + t.trackerId}
    </label>
  `).join('');
}

function saveSettings() {
  STATE.url = $('s-url').value.trim();
  STATE.autoSchemas = $('s-auto-schemas').checked;
  STATE.startView = $('s-start').value;

  // Validate URL
  if (!isValidTikiUrl(STATE.url)) {
    toast('❌ La URL debe ser HTTPS (o localhost para desarrollo)', 'error');
    return;
  }

  // Manual token: only update if user typed something new (field is blank by design)
  const manualToken = $('s-token').value.trim();
  if (!STATE.oauthMode) {
    if (manualToken) STATE.token = manualToken;
    // if empty, keep existing STATE.token (don't overwrite)
  }

  // OAuth2 config
  OAUTH.clientId = $('s-oauth-cid').value.trim();
  OAUTH.clientSecret = $('s-oauth-secret').value.trim();
  OAUTH.redirectUri = window.location.origin + window.location.pathname;

  // Whitelist
  STATE.hiddenTrackerIds.clear();
  document.querySelectorAll('.wl-cb').forEach(cb => { if (!cb.checked) STATE.hiddenTrackerIds.add(Number(cb.dataset.id)); });
  // Persist
  localStorage.setItem('tp_url', STATE.url);
  if (!STATE.oauthMode) localStorage.setItem('tp_token', STATE.token);
  else localStorage.removeItem('tp_token');
  Promise.all([
    savePref('autoSchemas', STATE.autoSchemas),
    savePref('startView', STATE.startView),
    savePref('hiddenTrackerIds', [...STATE.hiddenTrackerIds]),
    savePref('lastTrackerId', STATE.lastTrackerId),
    savePref('oauthClientId', OAUTH.clientId),
    savePref('oauthClientSecret', OAUTH.clientSecret),
    savePref('oauthRedirectUri', OAUTH.redirectUri)
  ]).then(() => {
    toast('✅ Configuración guardada', 'success');
    if (STATE.trackers.length === 0) {
      loadTrackers().then(() => initDashboard());
    } else {
      initDashboard();
    }
  });
}

function testConnectionHandler() {
  const url = $('s-url').value.trim();
  const status = $('s-status');
  const useOAuth = STATE.oauthMode && OAUTH.clientId && OAUTH.clientSecret;
  let token = $('s-token').value.trim();

  if (!url) { showStatus(status, 'Completá URL de TikiWiki', 'err'); return; }
  if (!isValidTikiUrl(url)) { showStatus(status, 'La URL debe ser HTTPS (o localhost)', 'err'); return; }
  STATE.url = url;

  if (useOAuth) {
    // Use OAuth2 access token if available, else trigger login
    if (STATE.token) {
      showStatus(status, '🔑 Usando token OAuth2...', 'loading');
      testConnection().then(data => {
        const count = (data.data || data.trackers || []).length;
        showStatus(status, '✅ Conectado vía OAuth2 — ' + count + ' trackers', 'ok');
        renderWhitelist(data.data || data.trackers || []);
      }).catch(err => {
        showStatus(status, '❌ ' + err.message + ' — Probá iniciar sesión', 'err');
      });
    } else {
      showStatus(status, 'ℹ️ Iniciá sesión con OAuth2 para probar la conexión', 'info');
    }
    return;
  }

  if (!token) { showStatus(status, 'Completá el Token manual o configurá OAuth2', 'err'); return; }
  STATE.token = token;
  showStatus(status, 'Probando conexión...', 'loading');
  testConnection().then(data => {
    const count = (data.data || data.trackers || []).length;
    showStatus(status, '✅ Conectado — ' + count + ' trackers encontrados', 'ok');
    renderWhitelist(data.data || data.trackers || []);
  }).catch(err => {
    showStatus(status, '❌ ' + err.message, 'err');
  });
}

function showStatus(el, msg, type) {
  el.className = 'conn-status ' + type;
  el.textContent = msg;
  el.classList.remove('hidden');
}

function resetSettings() {
  if (!confirm('¿Eliminar toda la configuración? Los datos guardados localmente se perderán.')) return;
  STATE.url = ''; STATE.token = ''; STATE.trackers = []; STATE.schemas = {};
  STATE.oauthMode = false;
  OAUTH.clientId = ''; OAUTH.clientSecret = ''; OAUTH.refreshToken = ''; OAUTH.expiresAt = 0;
  localStorage.removeItem('tp_url'); localStorage.removeItem('tp_token');
  sessionStorage.removeItem('tp_oauth_state');
  dbClear('prefs'); dbClear('trackers'); dbClear('schemas'); dbClear('queue');
  dbClear('synclog'); dbClear('trackerMeta');
  $('s-url').value = ''; $('s-token').value = ''; $('s-token').placeholder = 'tu-token-api';
  const tokenStatus = $('s-token-status');
  if (tokenStatus) { tokenStatus.className = 'token-status'; tokenStatus.textContent = ''; }
  $('s-oauth-cid').value = ''; $('s-oauth-secret').value = '';
  $('s-oauth-status').classList.add('hidden');
  $('s-status').classList.add('hidden');
  initSettings();
}

/* ==================================================================
   4. DASHBOARD
   ================================================================== */
function loadTrackers() {
  return getTrackers().then(list => {
    STATE.trackers = list;
    // Cache tracker names in IndexedDB
    return Promise.all(list.map(t => dbPut('trackers', { trackerId: t.trackerId, name: t.name, description: t.description })));
  });
}

function loadSchemas(trackerIds) {
  return Promise.all(trackerIds.map(id =>
    getTrackerFields(id).then(fields => {
      STATE.schemas[id] = fields;
      return dbPut('schemas', { trackerId: id, fields, lastFetched: Date.now() });
    }).catch(() => {/* skip if fails */})
  ));
}

function initDashboard() {
  switchView('dashboard');
  renderDashboard();
  updatePendingCount();
  if (STATE.autoSchemas && STATE.trackers.length) {
    const ids = STATE.trackers.map(t => t.trackerId).filter(id => !STATE.schemas[id]);
    if (ids.length) loadSchemas(ids);
  }
}

function renderDashboard() {
  const container = $('d-trackers');
  const empty = $('d-empty');
  const visible = STATE.trackers.filter(t => !STATE.hiddenTrackerIds.has(t.trackerId));
  if (!visible.length) {
    container.innerHTML = '';
    empty.classList.remove('hidden');
    return;
  }
  empty.classList.add('hidden');
  container.innerHTML = visible.map(t => {
    const pending = STATE.queue.filter(q => q.trackerId === t.trackerId).length;
    const cls = pending === 0 ? 'pending-0' : pending < 10 ? 'pending-low' : 'pending-high';
    const label = pending === 0 ? '✓ Sincronizado' : pending + ' pendiente' + (pending > 1 ? 's' : '');
    const emoji = guessEmoji(t.name);
    return `
      <div class="tracker-card" data-id="${t.trackerId}" onclick="openForm(${t.trackerId})">
        <div class="emoji">${emoji}</div>
        <div class="info">
          <div class="name">${esc(t.name || 'Tracker #' + t.trackerId)}</div>
          <div class="meta">${STATE.schemas[t.trackerId] ? STATE.schemas[t.trackerId].length + ' campos' : '…'}</div>
        </div>
        <div class="pending-badge ${cls}">${label}</div>
      </div>
    `;
  }).join('');
}

function guessEmoji(name) {
  if (!name) return '📋';
  const n = name.toLowerCase();
  if (n.includes('planta') || n.includes('árbol') || n.includes('flora') || n.includes('ceibo')) return '🌿';
  if (n.includes('agua') || n.includes('río') || n.includes('arroyo') || n.includes('calidad')) return '💧';
  if (n.includes('ave') || n.includes('pájaro') || n.includes('bird')) return '🐦';
  if (n.includes('foto') || n.includes('registro') || n.includes('imagen')) return '📸';
  if (n.includes('suelo') || n.includes('tierra')) return '🌍';
  if (n.includes('animal') || n.includes('fauna')) return '🐾';
  if (n.includes('meteor') || n.includes('clima') || n.includes('temp')) return '🌤';
  if (n.includes('mensaje') || n.includes('telegram') || n.includes('chat')) return '💬';
  return '📋';
}

function updatePendingCount() {
  const total = STATE.queue.length;
  const badge = $('header-badge');
  if (total > 0) { badge.textContent = total; badge.classList.remove('hidden'); }
  else { badge.classList.add('hidden'); }
  $('d-total-pending').textContent = total;
  $('d-sync-text').textContent = total > 0 ? total + ' pendientes' : 'Todo sincronizado ✓';
}

/* ==================================================================
   5. FORM RENDERER
   ================================================================== */
function openForm(trackerId) {
  STATE.currentTrackerId = trackerId;
  STATE.lastTrackerId = trackerId;
  savePref('lastTrackerId', trackerId);
  // Add to recent
  STATE.recentTrackers = [trackerId, ...STATE.recentTrackers.filter(id => id !== trackerId)].slice(0, 4);
  switchView('form');
  renderForm(trackerId);
}

function renderForm(trackerId) {
  const header = $('f-header');
  const form = $('f-form');

  // Pills
  const homePill = '<button class="pill home" onclick="switchView(\'dashboard\')">🏠</button>';
  const tracker = STATE.trackers.find(t => t.trackerId === trackerId);
  const trackerPill = `<span class="pill active">${esc(tracker ? tracker.name : 'Tracker')}</span>`;
  const recentPills = STATE.recentTrackers
    .filter(id => id !== trackerId && !STATE.hiddenTrackerIds.has(id))
    .slice(0, 3)
    .map(id => {
      const t = STATE.trackers.find(x => x.trackerId === id);
      return `<button class="pill" onclick="openForm(${id})">${esc(t ? t.name : '#' + id)}</button>`;
    }).join('');
  header.innerHTML = homePill + trackerPill + recentPills;

  // Fields — try cache first, then API
  const cached = STATE.schemas[trackerId];
  if (cached) {
    renderFields(form, trackerId, cached);
  } else {
    form.innerHTML = '<p class="text-center" style="padding:40px;color:var(--text-muted)">Cargando campos <span class="loading-dots"></span></p>';
    getTrackerFields(trackerId).then(fields => {
      STATE.schemas[trackerId] = fields;
      dbPut('schemas', { trackerId, fields, lastFetched: Date.now() });
      renderFields(form, trackerId, fields);
    }).catch(err => {
      form.innerHTML = '<p class="text-center" style="padding:40px;color:var(--red)">Error al cargar campos: ' + esc(err.message) + '</p>';
    });
  }
}

function renderFields(container, trackerId, fields) {
  if (!fields || !fields.length) {
    container.innerHTML = '<p class="text-center" style="padding:40px;color:var(--text-muted)">Este tracker no tiene campos</p>';
    return;
  }
  let html = '<form id="tp-form" onsubmit="return false">';
  html += '<input type="hidden" id="f-tracker-id" value="' + trackerId + '">';

  // Track file fields
  let fileFieldIndex = 0;

  fields.forEach(f => {
    const pn = f.permName;
    const label = esc(f.name || pn);
    const mandatory = f.isMandatory ? ' *' : '';
    const desc = f.description ? '<div style="font-size:.75rem;color:var(--text-muted);margin-top:2px">' + esc(f.description) + '</div>' : '';
    const reqAttr = f.isMandatory ? ' required' : '';

    html += '<div class="form-group" data-field="' + esc(pn) + '">';

    switch (f.type) {
      case 'a': // Textarea
        html += `<label>${label}${mandatory}</label>
          <textarea id="f-${esc(pn)}"${reqAttr} placeholder="${esc(f.description || '')}"></textarea>${desc}`;
        break;

      case 'n': // Number
        const opts = parseOptions(f.options);
        const min = opts.min !== undefined ? ' min="' + esc(String(opts.min)) + '"' : '';
        const max = opts.max !== undefined ? ' max="' + esc(String(opts.max)) + '"' : '';
        const step = opts.decimals !== undefined ? ' step="' + esc(String(Math.pow(0.1, opts.decimals))) + '"' : ' step="any"';
        const phMin = opts.min !== undefined ? esc(String(opts.min)) : '';
        const phMax = opts.max !== undefined ? esc(String(opts.max)) : '';
        html += `<label>${label}${mandatory}</label>
          <input type="number" id="f-${esc(pn)}"${min}${max}${step}${reqAttr} placeholder="${phMin ? phMin + ' - ' + (phMax || '∞') : ''}">${desc}`;
        break;

      case 'f': case 'j': // Datetime
        html += `<label>${label}${mandatory}</label>
          <input type="datetime-local" id="f-${esc(pn)}"${reqAttr}>${desc}`;
        break;

      case 'D': // Date
        html += `<label>${label}${mandatory}</label>
          <input type="date" id="f-${esc(pn)}"${reqAttr}>${desc}`;
        break;

      case 'G': // Geolocation
        html += `<label>${label}${mandatory}</label>
          <button type="button" class="gps-btn" data-field="${esc(pn)}" onclick="getGPS(this)">📍 Usar ubicación actual</button>
          <div class="gps-coords" id="f-${esc(pn)}-coords"></div>
          <input type="hidden" id="f-${esc(pn)}">${desc}`;
        break;

      case 'FG': { // File Gallery
        const fi = fileFieldIndex++;
        html += `<label>${label}${mandatory}</label>
          <div class="file-input">
            <input type="file" id="f-${esc(pn)}" accept="image/*,video/*"${reqAttr}>
            <div class="file-name" id="f-${esc(pn)}-name"></div>
          </div>${desc}`;
        // Store galleryId
        const gOpts = parseOptions(f.options);
        html += `<input type="hidden" class="fg-gallery-id" data-field="${esc(pn)}" value="${esc(String(gOpts.galleryId || ''))}">`;
        break;
      }

      case 'd': { // Dropdown (TikiWiki type 'd')
        const opts = parseDropdownOptions(f.options);
        html += `<label>${label}${mandatory}</label>
          <select id="f-${esc(pn)}"${reqAttr}>
            <option value="">— Seleccionar —</option>
            ${opts.map(o => '<option value="' + esc(o) + '">' + esc(o) + '</option>').join('')}
          </select>${desc}`;
        break;
      }

      case 'r': { // Item Link
        const opts = parseOptions(f.options);
        const tId = esc(String(opts.trackerId || ''));
        html += `<label>${label}${mandatory}</label>
          <input type="text" id="f-${esc(pn)}" placeholder="ID de item (tracker #${tId})"${reqAttr}>
          <div style="font-size:.75rem;color:var(--text-muted)">Item Link → tracker ${tId}</div>${desc}`;
        break;
      }

      case 'F': // Freetags
        html += `<label>${label}${mandatory}</label>
          <input type="text" id="f-${esc(pn)}" placeholder="etiqueta1 etiqueta2"${reqAttr}>${desc}`;
        break;

      default: // 't' and everything else
        html += `<label>${label}${mandatory}</label>
          <input type="text" id="f-${esc(pn)}"${reqAttr} placeholder="${esc(f.description || '')}">${desc}`;
    }
    html += '</div>';
  });

  html += `<div class="form-actions">
    <button type="button" class="btn btn-primary" onclick="submitForm()">💾 Guardar</button>
    <button type="button" class="btn btn-secondary" onclick="switchView('dashboard')">× Cancelar</button>
  </div>`;
  html += '</form>';
  container.innerHTML = html;

  // File input handlers
  container.querySelectorAll('input[type=file]').forEach(inp => {
    inp.addEventListener('change', () => {
      const nameEl = document.getElementById(inp.id + '-name');
      if (nameEl && inp.files.length) nameEl.textContent = inp.files[0].name;
    });
  });
}

/* ==================================================================
   6. FORM SUBMISSION
   ================================================================== */
function submitForm() {
  const trackerId = Number($('f-tracker-id').value);
  const fields = STATE.schemas[trackerId];
  if (!fields) return;

  const data = {};
  const files = {};
  let errors = [];

  fields.forEach(f => {
    const pn = f.permName;
    const el = document.getElementById('f-' + pn);
    if (!el) return;

    if (f.type === 'FG') {
      if (el.files && el.files.length > 0) files[pn] = el.files[0];
      return; // files uploaded separately
    }

    let val = el.value ? el.value.trim() : '';

    // Convert date/datetime strings to UNIX timestamps (TikiWiki expects seconds)
    if (val && (f.type === 'f' || f.type === 'j' || f.type === 'D')) {
      const ts = Math.floor(new Date(val).getTime() / 1000);
      if (!isNaN(ts)) val = String(ts);
    }

    // Validate mandatory
    if (f.isMandatory && !val) {
      errors.push('Falta: ' + (f.name || pn));
      return;
    }

    if (val && f.type === 'G') {
      // GPS comes as "lat,lng,zoom"
      data[pn] = val;
    } else if (val) {
      data[pn] = val;
    }
  });

  if (errors.length) {
    toast('❌ ' + errors.join(', '), 'error');
    return;
  }

  saveItem(trackerId, data, files, fields);
}

function saveItem(trackerId, data, files, fields) {
  // Build the fields payload: for FG, we upload and set file ID
  const payload = { ...data };

  // Process files — need to upload first if online
  const fileFields = Object.keys(files);
  const filePromises = fileFields.map(fieldName => {
    const fgField = fields.find(f => f.permName === fieldName && f.type === 'FG');
    if (!fgField) return Promise.resolve();
    const opts = parseOptions(fgField.options);
    const galleryId = opts.galleryId;
    if (!galleryId) { toast('⚠️ El campo ' + fieldName + ' no tiene galleryId configurado', 'error'); return Promise.resolve(); }
    return uploadFile(galleryId, files[fieldName]).then(result => {
      // The API returns file info; store fileId or URL
      payload[fieldName] = result.fileId || result.url || '';
    }).catch(err => {
      // If upload fails while online, queue the whole thing for later
      throw new Error('Error al subir archivo: ' + err.message);
    });
  });

  // If offline, skip upload and queue directly
  if (!STATE.online) {
    queueItem(trackerId, data, files, fields);
    return;
  }

  Promise.all(filePromises)
    .then(() => createItem(trackerId, payload))
    .then(result => {
      toast('✅ Item creado en tracker #' + trackerId, 'success');
      resetForm();
      updatePendingCount();
    })
    .catch(err => {
      // If upload or create fails while online, queue for retry
      console.warn('Error saving, queuing:', err);
      queueItem(trackerId, data, files, fields);
    });
}

function resetForm() {
  const form = document.getElementById('tp-form');
  if (form) form.reset();
  document.querySelectorAll('.gps-coords').forEach(el => el.textContent = '');
  document.querySelectorAll('.file-name').forEach(el => el.textContent = '');
}

/* ==================================================================
   7. OFFLINE QUEUE
   ================================================================== */
function queueItem(trackerId, data, files, fields) {
  const entry = {
    trackerId,
    data,
    files: {},  // store file references as { fieldName: { name, size, type } }
    createdAt: Date.now(),
    retries: 0,
    error: null
  };
  // For files, store metadata (can't store actual File in IndexedDB easily — we'll re-attach on sync)
  Object.keys(files).forEach(k => {
    entry.files[k] = { name: files[k].name, size: files[k].size, type: files[k].type };
  });
  STATE.queue.push(entry);
  dbPut('queue', entry).then(() => {
    toast('📦 Guardado offline. Pendiente de sincronizar.', 'info');
    updatePendingCount();
  }).catch(err => {
    console.error('queueItem: dbPut failed', err);
    // item is still in STATE.queue array for this session
  });
}

function syncAll() {
  if (_syncing) { toast('🔄 Ya sincronizando...', 'info'); return; }
  if (!STATE.queue.length) { toast('✓ Todo sincronizado', 'success'); return; }
  if (!STATE.online) { toast('⚠️ Sin conexión. Se reintentará automáticamente.', 'info'); return; }
  // Rate limit: max 1 sync every 3 seconds
  const now = Date.now();
  if (now - _lastSyncTime < 3000) { toast('⏳ Esperá unos segundos antes de sincronizar de nuevo', 'info'); return; }
  _lastSyncTime = now;

  _syncing = true;
  toast('🔄 Sincronizando ' + STATE.queue.length + ' items...', 'info');

  // Process queue sequentially
  const process = (index) => {
    if (index >= STATE.queue.length) {
      // Done
      _syncing = false;
      STATE.queue = [];
      updatePendingCount();
      renderDashboard();
      toast('✅ Sincronización completa', 'success');
      return;
    }

    const item = STATE.queue[index];
    processItem(item).then(() => {
      // Remove from queue
      STATE.queue.splice(index, 1);
      dbDelete('queue', item.id);
      process(index); // next
    }).catch(err => {
      item.retries++;
      item.error = err.message;
      if (item.retries >= 3) {
        // Move to synclog as failed
        dbPut('synclog', { ...item, syncedAt: Date.now(), status: 'failed' });
        STATE.queue.splice(index, 1);
        dbDelete('queue', item.id);
        toast('❌ Error en item: ' + err.message, 'error');
      } else {
        dbPut('queue', item);
      }
      process(index + 1);
    });
  };

  process(0);
  // If queue becomes empty (all failed already), release mutex
  if (STATE.queue.length === 0) _syncing = false;
}

function processItem(item) {
  // Re-create fields from data
  const fields = STATE.schemas[item.trackerId];
  if (!fields) return Promise.reject(new Error('Schema no disponible para tracker ' + item.trackerId));

  const payload = { ...item.data };

  // Handle file uploads — we need the original files which are not stored offline
  // For MVP: skip files that can't be re-uploaded and flag them
  const fileFieldNames = Object.keys(item.files);
  if (fileFieldNames.length > 0) {
    // Try to upload: file data not available offline, so we skip
    // In a future version, we'd store file blobs in IndexedDB
    console.warn('Files queued but not available for re-upload:', fileFieldNames);
    // Still try to create the item without files
  }

  return createItem(item.trackerId, payload).then(result => {
    return dbPut('synclog', {
      ...item,
      syncedAt: Date.now(),
      status: 'synced',
      remoteItemId: result.itemId || result.id || null
    });
  });
}

/* ==================================================================
   8. NAVIGATION
   ================================================================== */
function switchView(name) {
  STATE.currentView = name;
  Object.keys(views).forEach(k => views[k].classList.toggle('active', k === name));
  // Header
  const header = $('app-header');
  if (name === 'settings') {
    header.querySelector('h1').textContent = '⚙️ Configuración';
  } else if (name === 'dashboard') {
    header.querySelector('h1').innerHTML = '📋 TikiPickIt <span class="badge hidden" id="header-badge">0</span>';
    updatePendingCount();
  } else if (name === 'form') {
    header.querySelector('h1').textContent = '📝 Nuevo registro';
  }
}

/* ==================================================================
   9. GPS
   ================================================================== */
function getGPS(btn) {
  if (!navigator.geolocation) { toast('❌ GPS no disponible en este dispositivo', 'error'); return; }
  btn.disabled = true;
  btn.textContent = '📍 Obteniendo ubicación...';
  navigator.geolocation.getCurrentPosition(
    pos => {
      const lat = pos.coords.latitude.toFixed(6);
      const lng = pos.coords.longitude.toFixed(6);
      const field = btn.dataset.field;
      const coordsEl = document.getElementById('f-' + field + '-coords');
      const inputEl = document.getElementById('f-' + field);
      if (coordsEl) coordsEl.textContent = lng + ', ' + lat;
      if (inputEl) inputEl.value = lng + ',' + lat + ',15';
      btn.disabled = false;
      btn.textContent = '📍 Usar ubicación actual';
      toast('📍 Ubicación obtenida', 'success');
    },
    err => {
      btn.disabled = false;
      btn.textContent = '📍 Usar ubicación actual';
      toast('❌ Error de GPS: ' + err.message, 'error');
    },
    { enableHighAccuracy: true, timeout: 10000 }
  );
}

/* ==================================================================
   10. TOAST
   ================================================================== */
function toast(msg, type) {
  toastEl.textContent = msg;
  toastEl.className = 'toast ' + type + ' show';
  clearTimeout(toastEl._timeout);
  toastEl._timeout = setTimeout(() => toastEl.classList.remove('show'), 3000);
}

/* ==================================================================
   11. UTILITIES
   ================================================================== */
function esc(str) {
  if (typeof str !== 'string') return String(str || '');
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}

function isValidTikiUrl(str) {
  try {
    const u = new URL(str);
    return u.protocol === 'https:' || u.hostname === 'localhost' || u.hostname === '127.0.0.1';
  } catch { return false; }
}

function parseOptions(optStr) {
  if (!optStr) return {};
  try {
    const parsed = typeof optStr === 'string' ? JSON.parse(optStr) : optStr;
    if (Array.isArray(parsed)) {
      const obj = {};
      parsed.forEach(item => { if (item.name && item.value !== undefined) obj[item.name] = item.value; });
      return obj;
    }
    return parsed || {};
  } catch (_) { return {}; }
}

function parseDropdownOptions(optStr) {
  const opts = parseOptions(optStr);
  if (opts.options) return opts.options.split(',').map(s => s.trim()).filter(Boolean);
  return [];
}

/* ==================================================================
   12. INIT
   ================================================================== */
function init() {
  // Register SW
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(e => console.warn('SW registration failed:', e));
  }

  // Online/offline events
  window.addEventListener('online', () => {
    STATE.online = true;
    toast('📶 Conexión restablecida', 'info');
    if (STATE.queue.length > 0) syncAll();
    else updatePendingCount();
  });
  window.addEventListener('offline', () => {
    STATE.online = false;
    toast('📶 Sin conexión — los datos se guardarán localmente', 'info');
    updatePendingCount();
  });

  // Load base URL
  STATE.url = localStorage.getItem('tp_url') || '';
  $('s-url').value = STATE.url;

  // Check for OAuth2 callback first (before loading any tokens)
  // The redirect_uri is the current page URL with ?code= from TikiWiki
  OAUTH.redirectUri = window.location.origin + window.location.pathname;
  if (oauth2HandleCallback()) return; // handled: exchanging code or showing error

  // Load OAuth2 config + tokens
  oauth2LoadConfig().then(() => {
    // If OAuth2 mode but no token loaded, show settings for login
    if (STATE.oauthMode && !STATE.token) {
      initSettings();
      return;
    }

    // Load manual token fallback if not OAuth2 (never put actual token in DOM)
    if (!STATE.oauthMode) {
      STATE.token = localStorage.getItem('tp_token') || '';
    }

    // If no credentials at all, show settings
    if ((!STATE.oauthMode && (!STATE.url || !STATE.token)) ||
        (STATE.oauthMode && (!STATE.url || !OAUTH.clientId || !OAUTH.clientSecret))) {
      initSettings();
      return;
    }

    // Load prefs
    return Promise.all([
      loadPref('autoSchemas', true),
      loadPref('startView', 'dashboard'),
      loadPref('hiddenTrackerIds', []),
      loadPref('lastTrackerId', null),
      dbGetAll('schemas')
    ]).then(([autoSchemas, startView, hiddenIds, lastTrackerId, schemas]) => {
      STATE.autoSchemas = autoSchemas;
      STATE.startView = startView;
      STATE.lastTrackerId = lastTrackerId;
      STATE.hiddenTrackerIds = new Set(hiddenIds || []);
      schemas.forEach(s => { STATE.schemas[s.trackerId] = s.fields; });

      return dbGetAll('queue');
    }).then(queue => {
      STATE.queue = queue || [];
      return loadTrackers();
    }).then(() => {
      initSettings();
      if (STATE.startView === 'last' && STATE.lastTrackerId && STATE.schemas[STATE.lastTrackerId]) {
        openForm(STATE.lastTrackerId);
      } else {
        initDashboard();
      }
      if (STATE.queue.length > 0 && STATE.online) syncAll();
    });
  }).catch(err => {
    console.error('Init error:', err);
    initSettings();
  });
}

function initSettings() {
  switchView('settings');
  $('s-auto-schemas').checked = STATE.autoSchemas;
  $('s-start').value = STATE.startView;
  $('s-oauth-cid').value = OAUTH.clientId;
  $('s-oauth-secret').value = OAUTH.clientSecret;
  // Set redirect URI demo
  const redirectDemo = $('s-oauth-redirect-demo');
  if (redirectDemo) redirectDemo.textContent = window.location.origin + window.location.pathname;

  // Manual token: show masked placeholder if already configured, never expose in value
  const tokenInput = $('s-token');
  const tokenStatus = $('s-token-status');
  if (STATE.token && !STATE.oauthMode) {
    tokenInput.value = '';
    tokenInput.placeholder = '••••••••••••••••••••••••';
    if (tokenStatus) { tokenStatus.className = 'token-status ok'; tokenStatus.textContent = '✓ Token configurado'; }
  } else {
    tokenInput.placeholder = 'tu-token-api';
    if (tokenStatus) { tokenStatus.className = 'token-status'; tokenStatus.textContent = ''; }
  }

  // Show OAuth2 status
  const oauthStatus = $('s-oauth-status');
  if (STATE.oauthMode) {
    const expiresIn = OAUTH.expiresAt ? Math.round((OAUTH.expiresAt - Date.now()) / 60000) : 0;
    oauthStatus.className = 'conn-status ok';
    oauthStatus.textContent = '✅ OAuth2 activo' + (expiresIn > 0 ? ' (token expira en ' + expiresIn + ' min)' : '');
    oauthStatus.classList.remove('hidden');
  } else if (OAUTH.clientId && OAUTH.clientSecret) {
    oauthStatus.className = 'conn-status info';
    oauthStatus.textContent = 'ℹ️ OAuth2 configurado — usá Token manual o iniciá sesión OAuth2';
    oauthStatus.classList.remove('hidden');
  } else {
    oauthStatus.classList.add('hidden');
  }
  if (STATE.trackers.length) renderWhitelist(STATE.trackers);
}

/* ─── Event bindings ─── */
document.addEventListener('DOMContentLoaded', () => {
  $('btn-settings').addEventListener('click', showSettings);
  $('s-test').addEventListener('click', testConnectionHandler);
  $('s-save').addEventListener('click', saveSettings);
  $('s-reset').addEventListener('click', resetSettings);
  $('s-token-eye').addEventListener('click', () => {
    const inp = $('s-token');
    inp.type = inp.type === 'password' ? 'text' : 'password';
  });
  $('s-oauth-login').addEventListener('click', oauth2Login);
  $('s-oauth-logout').addEventListener('click', oauth2Logout);
  init();
});
