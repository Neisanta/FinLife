/* Comparação REAL local ↔ produção, arquivo por arquivo (hash + tamanho). */
const http = require('http');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const ROOT = 'C:\\xampp\\htdocs\\FinLife\\public\\assets';
const REMOTE = 'http://finlife.spssolar.com.br/assets';

const FILES = [
    'css/app.css',
    'js/app.js',
    'js/charts.js',
    'js/market-charts.js',
    'js/pages/dashboard.js',
    'js/pages/transactions.js',
    'js/pages/accounts.js',
    'js/pages/cards.js',
    'js/pages/markets.js',
    'js/pages/categories.js',
    'js/pages/goals.js',
    'js/pages/ai.js',
    'js/pages/commitments.js',
];

function fetchRemote(url) {
    return new Promise((resolve) => {
        http.get(url, (res) => {
            if (res.statusCode !== 200) { res.resume(); resolve({ status: res.statusCode, body: null }); return; }
            const chunks = [];
            res.on('data', c => chunks.push(c));
            res.on('end', () => resolve({ status: 200, body: Buffer.concat(chunks) }));
        }).on('error', (e) => resolve({ status: 0, body: null, err: e.message }));
    });
}

async function main() {
    console.log('=== COMPARAÇÃO LOCAL ↔ PRODUÇÃO (assets públicos) ===\n');
    const rows = [];
    for (const f of FILES) {
        const localPath = path.join(ROOT, f.replace(/\//g, '\\'));
        const localExists = fs.existsSync(localPath);
        const localBuf = localExists ? fs.readFileSync(localPath) : null;
        const localHash = localBuf ? crypto.createHash('sha256').update(localBuf).digest('hex').slice(0, 12) : '—';
        const localSize = localBuf ? localBuf.length : 0;

        const rem = await fetchRemote(`${REMOTE}/${f}`);
        const remHash = rem.body ? crypto.createHash('sha256').update(rem.body).digest('hex').slice(0, 12) : '—';
        const remSize = rem.body ? rem.body.length : 0;

        let verdict;
        if (rem.status !== 200) { verdict = 'AUSENTE NO SERVIDOR (' + rem.status + ')'; }
        else if (!localExists) { verdict = 'SÓ NO SERVIDOR'; }
        else if (localHash === remHash) { verdict = 'IGUAL'; }
        else { verdict = 'DIFERENTE'; }

        rows.push({ file: f, verdict, localSize, remSize, localHash, remHash });
        const mark = verdict === 'IGUAL' ? '  OK   ' : '  DIFF ';
        console.log(mark + f + ' → ' + verdict + ' (local ' + (localSize / 1024).toFixed(1) + 'KB / remoto ' + (remSize / 1024).toFixed(1) + 'KB)');
    }

    console.log('\n=== RESUMO ===');
    const g = {};
    rows.forEach(r => { g[r.verdict] = (g[r.verdict] || 0) + 1; });
    Object.entries(g).forEach(([k, v]) => console.log(k + ': ' + v));
    fs.writeFileSync(path.join(__dirname, 'deploy-comparison.json'), JSON.stringify(rows, null, 1));
    console.log('detalhes: scripts/deploy-comparison.json');
    process.exit(0);
}
main().catch(e => { console.error('ERRO: ' + e.message); process.exit(1); });
