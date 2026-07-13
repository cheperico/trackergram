/**
 * trackerGram — Importación de exports ZIP de Telegram
 */

/* Control de cancelación */
var importAbortController = null;
var importCancelled = false;
var currentExtractId = null;

/* showProgress global para que processChunks pueda usarla */
window.showProgress = function(current, total, label) {
    var resultDiv = document.getElementById('import-result');
    if (!resultDiv) return;
    var pct = total > 0 ? Math.round((current / total) * 100) : 0;
    resultDiv.innerHTML = '';
    
    var container = document.createElement('div');
    container.style.cssText = 'background:var(--card-bg);border:1px solid var(--border);border-radius:8px;padding:16px;';
    
    var labelEl = document.createElement('div');
    labelEl.style.cssText = 'margin-bottom:8px;color:var(--text);';
    labelEl.textContent = label + ' (' + current + ' / ' + total + ')';
    container.appendChild(labelEl);
    
    var barOuter = document.createElement('div');
    barOuter.style.cssText = 'background:var(--border);border-radius:4px;height:20px;overflow:hidden;';
    
    var barInner = document.createElement('div');
    barInner.style.cssText = 'background:#4caf50;height:100%;width:' + pct + '%;transition:width 0.3s;border-radius:4px;';
    barInner.textContent = pct + '%';
    barInner.style.cssText += ';color:#fff;text-align:center;font-size:12px;line-height:20px;';
    // ARIA progressbar
    barInner.setAttribute('role', 'progressbar');
    barInner.setAttribute('aria-valuenow', pct);
    barInner.setAttribute('aria-valuemin', '0');
    barInner.setAttribute('aria-valuemax', '100');
    barInner.setAttribute('aria-valuetext', label + ' (' + current + ' / ' + total + ')');
    barOuter.appendChild(barInner);
    
    container.appendChild(barOuter);
    resultDiv.appendChild(container);
};

/* Mostrar/ocultar botones de import/cancel */
function setImportButtons(importing) {
    var startBtn = document.getElementById('import-start-btn');
    var cancelBtn = document.getElementById('import-cancel-btn');
    if (startBtn) startBtn.style.display = importing ? 'none' : '';
    if (cancelBtn) cancelBtn.style.display = importing ? '' : 'none';
}

function cancelImport() {
    importCancelled = true;
    
    // Abortar fetch en curso si existe
    if (importAbortController) {
        importAbortController.abort();
        importAbortController = null;
    }
    
    // Limpiar en el servidor
    if (currentExtractId) {
        var data = new URLSearchParams();
        data.append('mode', 'cancel');
        data.append('extract_id', currentExtractId);
        data.append('csrf_token', document.querySelector('#import-form [name=csrf_token]').value);
        fetch('import.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: data.toString()
        }).catch(function() {});
        currentExtractId = null;
    }
    
    var resultDiv = document.getElementById('import-result');
    if (resultDiv) {
        resultDiv.innerHTML = '';
        resultDiv.style.color = '';
        var div = document.createElement('div');
        div.style.cssText = 'background:#fff3e0;padding:14px;border-radius:8px;color:#e65100;';
        div.textContent = 'Importacion cancelada';
        resultDiv.appendChild(div);
    }
    
    setImportButtons(false);
}

function startImport() {
    var form = document.getElementById('import-form');
    var formData = new FormData(form);
    formData.append('mode', 'extract');
    var resultDiv = document.getElementById('import-result');
    
    function setResult(text, isError) {
        resultDiv.innerHTML = '';
        if (isError) resultDiv.style.color = 'var(--error)';
        else resultDiv.style.color = '';
        resultDiv.textContent = text;
    }
    
    importCancelled = false;
    currentExtractId = null;
    setImportButtons(true);
    setResult('Extrayendo archivo ZIP...');
    
    importAbortController = new AbortController();
    
    fetch('import.php', {
        method: 'POST',
        body: formData,
        signal: importAbortController.signal
    })
    .then(function(r) {
        if (!r.ok) {
            return r.text().then(function(text) {
                try { var err = JSON.parse(text); throw new Error(err.error || 'HTTP ' + r.status); }
                catch(e) { if (e.message !== text) throw e; throw new Error('HTTP ' + r.status + ': ' + text.substring(0,200)); }
            });
        }
        return r.text();
    })
    .then(function(text) {
        var data;
        try { data = JSON.parse(text); } catch(e) {
            throw new Error('Respuesta invalida: ' + text.substring(0, 200));
        }
        if (data.error) throw new Error(data.error);
        if (data.status !== 'extracted') throw new Error('Respuesta inesperada');
        
        if (importCancelled) return;
        currentExtractId = data.extract_id;
        return processChunks(data.extract_id, data.total, data.chat_title, data.topics_found);
    })
    .then(function(result) {
        if (!result || importCancelled) return;
        resultDiv.innerHTML = '';
        var div = document.createElement('div');
        div.style.cssText = 'background:#e8f5e9;padding:14px;border-radius:8px;color:#2e7d32;';
        
        var title = document.createElement('p');
        title.style.cssText = 'margin:0 0 8px 0;font-weight:bold;';
        title.textContent = 'Importacion completada';
        div.appendChild(title);
        
        var lines = [
            'Mensajes importados: ' + result.imported,
            'Actualizados (edit): ' + result.updated,
            'Saltados (ya existian): ' + result.skipped,
            'Fallados: ' + result.failed,
            'Archivos subidos: ' + result.media_processed,
            'Topics encontrados: ' + result.topics
        ];
        lines.forEach(function(line) {
            var p = document.createElement('p');
            p.style.cssText = 'margin:0;';
            p.textContent = line;
            div.appendChild(p);
        });
        
        resultDiv.appendChild(div);
        currentExtractId = null;
    })
    .catch(function(error) {
        if (error.name === 'AbortError') return; // cancelación, no mostrar error
        resultDiv.innerHTML = '';
        resultDiv.style.color = 'var(--error)';
        resultDiv.textContent = 'Error: ' + error.message;
    })
    .finally(function() {
        setImportButtons(false);
        importAbortController = null;
    });
}

function processChunks(extractId, total, chatTitle, topicsFound) {
    var offset = 0;
    var batchSize = 10;
    var accumulated = { imported: 0, updated: 0, skipped: 0, failed: 0, media_processed: 0, topics: topicsFound || 0 };
    
    function nextChunk() {
        return new Promise(function(resolve, reject) {
            if (importCancelled) { resolve(accumulated); return; }
            
            showProgress(offset, total, 'Importando mensajes');
            
            var data = new URLSearchParams();
            data.append('mode', 'process');
            data.append('extract_id', extractId);
            data.append('offset', offset);
            data.append('batch_size', batchSize);
            data.append('csrf_token', document.querySelector('#import-form [name=csrf_token]').value);
            
            importAbortController = new AbortController();
            
            fetch('import.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: data.toString(),
                signal: importAbortController.signal
            })
            .then(function(r) {
                var httpStatus = r.status;
                if (!r.ok) {
                    return r.text().then(function(text) {
                        try { var err = JSON.parse(text); throw new Error(err.error || 'HTTP ' + httpStatus); }
                        catch(e) { throw new Error('HTTP ' + httpStatus + ' - ' + text.substring(0,200)); }
                    });
                }
                return r.text();
            })
            .then(function(text) {
                if (importCancelled) { resolve(accumulated); return; }
                
                var result;
                try { result = JSON.parse(text); } catch(e) {
                    throw new Error('HTTP 200 - Respuesta no es JSON valido: ' + text.substring(0, 200));
                }
                if (result.error) throw new Error(result.error);
                
                accumulated.imported += result.imported || 0;
                accumulated.updated += result.updated || 0;
                accumulated.skipped += result.skipped || 0;
                accumulated.failed += result.failed || 0;
                accumulated.media_processed += result.media_processed || 0;
                offset = result.offset || offset;
                
                if (result.more) {
                    setTimeout(function() { nextChunk().then(resolve).catch(reject); }, 100);
                } else {
                    resolve(accumulated);
                }
            })
            .catch(function(err) {
                if (err.name === 'AbortError') { resolve(accumulated); return; }
                reject(err);
            });
        });
    }
    
    return nextChunk();
}
