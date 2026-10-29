/**
 * FinLife — Ponte Playwright/axe-core/Lighthouse.
 * Uso:
 *   node tools/web-audit.js <url> [--a11y] [--lighthouse] [--out <dir>]
 * Requer: playwright-core + axe-core + lighthouse (npm -g), Edge/Chrome local.
 */
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const url = process.argv[2] || 'http://127.0.0.1:8090/dashboard';
const doA11y = process.argv.includes('--a11y');
const doLighthouse = process.argv.includes('--lighthouse');
const outIdx = process.argv.indexOf('--out');
const OUT = outIdx > -1 ? process.argv[outIdx + 1] : process.env.TEMP + '\\fl_web_audit';
const BASE_LOG = 'http://127.0.0.1:8090/login';

function globalRoot() {
    try {
        // npm root -g JÁ retorna o diretório node_modules global
        return execSync(`npm.cmd root -g`, { encoding: 'utf8' }).trim();
    } catch (e) { return null; }
}

async function main() {
    if (!fs.existsSync(OUT)) { fs.mkdirSync(OUT, { recursive: true }); }
    const g = globalRoot();
    const { chromium } = require(path.join(g, 'playwright-core'));

// Credencial do usuário de teste: vem do ambiente, nunca fica no código.
// Defina FINLIFE_TEST_PASSWORD no shell antes de rodar.
if (!process.env.FINLIFE_TEST_PASSWORD) {
  console.error('Defina FINLIFE_TEST_PASSWORD antes de rodar este script.');
  process.exit(1);
}


    console.log('== Abrindo ' + url + ' (Edge headless) ==');
    const browser = await chromium.launch({ channel: 'msedge', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();
    // sempre passa pelo /login: loga e só então navega para a URL alvo (evita redirects no meio do evaluate)
    await page.goto(BASE_LOG, { waitUntil: 'load', timeout: 45000 }).catch(() => {});
    await page.waitForTimeout(800);
    const csrf = await page.evaluate(() => (document.querySelector('meta[name="csrf-token"]') || { content: '' }).content);
    await page.evaluate(async (token) => {
        await fetch('/api/auth.php?action=login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
            credentials: 'same-origin',
            body: JSON.stringify({ email: 'redesign@teste.com', password: process.env.FINLIFE_TEST_PASSWORD })
        });
    }, csrf);
    await page.goto(url, { waitUntil: 'load', timeout: 45000 }).catch(() => {});
    await page.waitForTimeout(2200);

    // 1) Auditoria estrutural rápida (layout)
    const layout = await page.evaluate(() => {
        const vw = document.documentElement.clientWidth;
        const out = { viewport: vw, overflow: document.documentElement.scrollWidth - vw, issues: [] };
        document.querySelectorAll('body *').forEach((e) => {
            const r = e.getBoundingClientRect();
            if (r.width > 4 && r.right > vw + 2 && out.issues.length < 8) {
                out.issues.push('estoura à direita: ' + e.tagName + '.' + String(e.className).split(' ')[0] + ' (' + Math.round(r.right) + ')');
            }
        });
        return out;
    });
    console.log('== Layout ==');
    console.log('  viewport: ' + layout.viewport + 'px · overflow: ' + layout.overflow + 'px');
    layout.issues.forEach(i => console.log('  ⚠ ' + i));
    if (!layout.issues.length) { console.log('  ✓ sem elementos estourando'); }

    // 2) Acessibilidade (axe-core)
    if (doA11y) {
        const axePath = path.join(g, 'axe-core', 'axe.min.js');
        await page.addScriptTag({ path: axePath });
        const results = await page.evaluate(async () => { return await window.axe.run(document, { resultTypes: ['violations'] }); });
        console.log('== Acessibilidade (axe-core, WCAG AA) ==');
        console.log('  violações: ' + results.violations.length);
        results.violations.forEach(v => {
            console.log('  ⚠ [' + v.impact + '] ' + v.id + ' — ' + v.help + ' (' + v.nodes.length + ' nó(s))');
            if (v.nodes[0]) { console.log('      ex.: ' + v.nodes[0].target.join(' ')); }
        });
        fs.writeFileSync(path.join(OUT, 'axe-' + new Date().toISOString().slice(0, 10) + '.json'), JSON.stringify(results.violations, null, 1));
        // passou/falhou contraste especificamente
        const contrast = results.violations.filter(v => v.id === 'color-contrast');
        console.log('  contraste: ' + (contrast.length ? contrast[0].nodes.length + ' elemento(s) abaixo de 4.5:1' : '✓ todos acima de 4.5:1'));
    }

    // screenshot para inspeção visual
    const shot = path.join(OUT, 'page-' + new URL(url).hostname + '-' + Date.now() + '.png');
    await page.screenshot({ path: shot, fullPage: false });
    console.log('== Screenshot: ' + shot + ' ==');

    await browser.close();

    // 3) Lighthouse (performance/SEO/best-practices/a11y)
    if (doLighthouse) {
        console.log('== Lighthouse (pode levar ~30s) ==');
        try {
            const lhJs = path.join(g, 'lighthouse', 'cli', 'index.js') || path.join(g, 'lighthouse', 'cli', 'index.cjs');
            const outLh = path.join(OUT, 'lighthouse-' + Date.now() + '.json');
            execSync(`node "${path.join(g, 'lighthouse', 'cli', 'index.js')}" "${url}" --output=json --output-path="${outLh}" --chrome-flags="--headless=new" --only-categories=performance,accessibility,best-practices,seo --quiet`, { stdio: 'pipe', timeout: 180000 });
            const report = JSON.parse(fs.readFileSync(outLh, 'utf8'));
            const cats = report.categories;
            console.log('  Performance: ' + Math.round(cats.performance.score * 100) + '/100');
            console.log('  Acessibilidade: ' + Math.round(cats.accessibility.score * 100) + '/100');
            console.log('  Best Practices: ' + Math.round(cats['best-practices'].score * 100) + '/100');
            console.log('  SEO: ' + Math.round(cats.seo.score * 100) + '/100');
        } catch (e) {
            console.log('  lighthouse falhou: ' + String(e.message).slice(0, 120));
        }
    }
    console.log('== Concluído — relatórios em ' + OUT + ' ==');
}
main().catch(e => { console.error('ERRO: ' + e.message); if (e.stack) { console.error(e.stack.split('\n').slice(0, 6).join('\n')); } process.exit(1); });
