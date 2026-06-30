/**
 * Iniciar importación de export ZIP de Telegram
 */
function startImport() {
    var form = document.getElementById('import-form');
    var formData = new FormData(form);
    formData.append('mode', 'extract');
    var resultDiv = document.getElementById('import-result');
    var importBtn = form.querySelector('button');
    
    function setResult(text, isError) {
        resultDiv.innerHTML = '';
        if (isError) resultDiv.style.color = 'var(--error)';
        else resultDiv.style.color = '';
        resultDiv.textContent = text;
    }
    
    function showProgress(current, total, label) {
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
    }
    
    importBtn.disabled = true;
    importBtn.textContent = 'Importando...';
    
    setResult('Extrayendo archivo ZIP...');
    
    fetch('import.php', {
        method: 'POST',
        body: formData
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
        
        return processChunks(data.extract_id, data.total, data.chat_title, data.topics_found);
    })
    .then(function(result) {
        resultDiv.innerHTML = '';
        var div = document.createElement('div');
        div.style.cssText = 'background:#e8f5e9;padding:14px;border-radius:8px;color:#2e7d32;';
        
        var title = document.createElement('p');
        title.style.cssText = 'margin:0 0 8px 0;font-weight:bold;';
        title.textContent = 'Importacion completada';
        div.appendChild(title);
        
        var lines = [
            'Mensajes importados: ' + result.imported,
            'Errores: ' + result.skipped,
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
    })
    .catch(function(error) {
        resultDiv.innerHTML = '';
        resultDiv.style.color = 'var(--error)';
        resultDiv.textContent = 'Error: ' + error.message;
    })
    .finally(function() {
        importBtn.disabled = false;
        importBtn.textContent = 'Importar';
    });
}

function processChunks(extractId, total, chatTitle, topicsFound) {
    var offset = 0;
    var batchSize = 50;
    var accumulated = { imported: 0, skipped: 0, media_processed: 0, topics: topicsFound || 0 };
    
    function nextChunk() {
        return new Promise(function(resolve, reject) {
            showProgress(offset, total, 'Importando mensajes');
            
            var data = new URLSearchParams();
            data.append('mode', 'process');
            data.append('extract_id', extractId);
            data.append('offset', offset);
            data.append('batch_size', batchSize);
            data.append('csrf_token', document.querySelector('#import-form [name=csrf_token]').value);
            
            fetch('import.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: data.toString()
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
                var result;
                try { result = JSON.parse(text); } catch(e) {
                    throw new Error('Respuesta invalida en lote: ' + text.substring(0, 200));
                }
                if (result.error) throw new Error(result.error);
                
                accumulated.imported += result.imported || 0;
                accumulated.skipped += result.skipped || 0;
                accumulated.media_processed += result.media_processed || 0;
                offset = result.offset || offset;
                
                if (result.more) {
                    setTimeout(function() { nextChunk().then(resolve).catch(reject); }, 100);
                } else {
                    resolve(accumulated);
                }
            })
            .catch(reject);
        });
    }
    
    return nextChunk();
}
