(() => {
	const C = window.VAULTLY, $ = (s) => document.querySelector(s), $$ = (s) => document.querySelectorAll(s);
	const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
	// translated string with printf-style %s / %d / %1$s placeholders (strings come from PHP, see VAULTLY.i18n)
	const T = (k, ...a) => { let i = 0; return (C.i18n[k] || k).replace(/\\n/g, '\n').replace(/%(?:(\d)\$)?[sd]/g, (m, n) => a[n ? n - 1 : i++]); };
	const box = $('#vaultly-modal'), bar = $('#vaultly-bar'), msg = $('#vaultly-msg'), pct = $('#vaultly-pct'), cancel = $('#vaultly-cancel'),
		eta = $('#vaultly-eta'), detail = $('#vaultly-detail'), note = $('#vaultly-note'), closeBtn = $('#vaultly-close'), title0 = document.title;
	let cancelled = false, t0 = 0;
	closeBtn.onclick = () => { box.hidden = true; document.title = title0; };
	const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text) e.textContent = text; return e; };
	const dur = (s) => (s >= 60 ? T('min', Math.floor(s / 60), s % 60) : T('sec', s));

	const post = async (action, data = {}, retries = 0) => {
		for (let n = 0; ; n++) {
			try {
				const f = new FormData();
				f.append('action', action);
				f.append('_ajax_nonce', C.nonce);
				for (const k in data) f.append(k, data[k]);
				const r = await fetch(C.ajax, { method: 'POST', body: f, credentials: 'same-origin' });
				let j;
				try { j = await r.json(); } catch (e) { throw new Error(T('serverError', r.status)); }
				if (!j.success) throw Object.assign(new Error(j.data || T('failed')), { fatal: true });
				return j.data;
			} catch (e) {
				if (e.fatal || n >= retries) throw e;
				await sleep(2000); // network/timeouts: every step is resumable, so just retry
			}
		}
	};

	const show = (text, p, err, extra) => {
		box.hidden = false;
		$('#vaultly-done').hidden = true;
		$('#vaultly-run').hidden = false;
		msg.textContent = text;
		box.classList.toggle('is-error', !!err);
		$('#vaultly-spin').hidden = !!err;
		note.hidden = !!err;
		closeBtn.hidden = !err;
		detail.textContent = extra || '';
		if (p != null) {
			bar.style.width = p + '%';
			pct.textContent = Math.floor(p) + '%';
			document.title = Math.floor(p) + '% - ' + title0;
			const el = Math.round((Date.now() - t0) / 1000);
			eta.textContent = t0 ? T('elapsed', dur(el)) + (p >= 3 && p < 100 ? T('left', dur(Math.round(el * (100 - p) / p))) : '') : '';
		}
		if (err) document.title = title0;
	};
	const lock = (on) => $$('.vaultly button, .vaultly label.button').forEach((b) => { if (b.id !== 'vaultly-cancel') b.disabled = on; });

		const head = (title, steps, idx) => {
		$('#vaultly-ptitle').textContent = title || '';
		const ol = $('#vaultly-steps');
		ol.hidden = !steps;
		if (!steps) return;
		ol.replaceChildren(...steps.map((t, i) => { const li = document.createElement('li'); li.textContent = t; li.className = i < idx ? 'is-done' : i === idx ? 'is-active' : ''; return li; }));
	};

	// "Your data has been restored successfully!" window (what All-in-One WP Migration shows), per process
	const success = (s) => {
		document.title = title0;
		const d = $('#vaultly-done');
		$('#vaultly-run').hidden = true;
		d.hidden = false;
		d.replaceChildren(el('h2', 'vaultly-ok', { backup: T('okBackup'), restore: T('okRestore'), verify: T('okVerify') }[s.kind] || T('done')));
		d.append(el('p', 'vaultly-sub', s.message));
		if (s.kind === 'restore') {
			const ol = el('ol', 'vaultly-next');
			const a = el('a', '', T('permalinksT'));
			a.href = C.permalinks; a.target = '_blank'; a.rel = 'noopener';
			const l1 = el('li'); l1.append(T('saveTwice') + ' ', a, ' ' + T('newWindow'));
			ol.append(l1, el('li', '', T('clearCache')));
			d.append(el('p', 'vaultly-sub', T('twoSteps')), ol);
		}
		const row = el('div', 'vaultly-btns');
		const ok = el('button', 'button', T('close'));
		ok.onclick = () => location.reload();
		if (s.kind === 'backup' && s.file) {
			const box = el('div', 'vaultly-linkbox');
			const url = el('div', 'vaultly-url', '…'); // the whole link, wrapped (never cut off)
			const cp = el('button', 'button', T('copy'));
			const dl = el('button', 'button button-primary', T('download'));
			cp.disabled = dl.disabled = true;
			box.append(url, cp);
			d.append(el('p', 'vaultly-sub', T('linkNote')), box);
			row.append(dl, ' ', ok);
			link(s.file).then((u) => {
				url.textContent = u;
				cp.disabled = dl.disabled = false;
				cp.onclick = () => { try { navigator.clipboard.writeText(u); } catch (e) { const r = document.createRange(); r.selectNodeContents(url); getSelection().removeAllRanges(); getSelection().addRange(r); document.execCommand('copy'); } cp.textContent = T('copied'); setTimeout(() => (cp.textContent = T('copy')), 1800); };
				dl.onclick = () => save(u);
			}).catch((e) => { url.textContent = e.message; });
		} else {
			ok.className = 'button button-primary';
			row.append(ok);
		}
		d.append(row);
	};

	async function drive(token) {
		for (;;) {
			if (cancelled) throw new Error(T('cancelled'));
			const s = await post('vaultly_step', { token }, 6);
			if (s.busy) { await sleep(1000); continue; }
			if (s.retry) { show(s.message, null, false); await sleep(2000); continue; }
			if (s.done) { success(s); return; }
			head(s.title, s.steps, s.index);
			cancel.hidden = !s.cancel;
			show(s.message, s.percent, false, s.detail);
		}
	}

	async function job(type, extra = {}) {
		lock(true);
		cancelled = false;
		t0 = Date.now();
		head(T('t_' + type), null);
		show(T('starting'), 0);
		cancel.hidden = true;
		try {
			const { token } = await post('vaultly_start', { type, ...extra });
			await drive(token);
		} catch (e) {
			head('', null);
			show(e.message, 0, true);
			lock(false);
			cancel.hidden = true;
		}
	}
	cancel.onclick = async (e) => {
		e.preventDefault();
		cancelled = true;
		try { await post('vaultly_cancel'); } catch (x) { /* ignore */ }
	};

	async function upload(file) {
		lock(true);
		t0 = Date.now();
		try {
			const fp = file.size + '-' + file.lastModified;
			let chunk = C.chunk, off = 0, fails = 0;
			try { off = Math.min((await post('vaultly_upload_status', { name: file.name, fp })).offset, file.size); } catch (e) { off = 0; }
			if (off === file.size) off = 0; // finished part that was never promoted: restart cleanly
			while (off < file.size) {
				const blob = file.slice(off, off + chunk);
				try {
					await post('vaultly_upload', { name: file.name, fp, offset: off, total: file.size, chunk: blob }, 2);
					off += blob.size;
					fails = 0;
				} catch (e) {
					if (e.fatal || ++fails > 3 || chunk <= 262144) throw e;
					chunk = Math.max(262144, chunk >> 1); // server/proxy rejected the size: halve and retry
					continue;
				}
				show(T('uploading', file.name), (100 * off) / file.size, false, T('mbOf', off >> 20, file.size >> 20));
			}
			show(T('uploaded'), 100);
			location.reload();
		} catch (e) {
			show(e.message, 0, true);
			lock(false);
		}
	}

	$('#vaultly-backup').onclick = () => job('backup', { scope: ($('input[name=vaultly-scope]:checked') || {}).value || 'full' });
	$('#vaultly-file').onchange = (e) => e.target.files[0] && upload(e.target.files[0]);
	const link = async (name) => (await post('vaultly_dl_link', { name })).url; // direct public URL of the file
	const save = (url) => { const a = el('a'); a.href = url; a.download = ''; document.body.append(a); a.click(); a.remove(); }; // start the download from the public URL
	const download = async (name) => {
		const url = await link(name);
		save(url);
		$('#vaultly-link-url').value = url;
		$('#vaultly-link-go').href = url;
		$('#vaultly-link').hidden = false;
		$('#vaultly-link').scrollIntoView({ block: 'nearest' });
	};
	$$('.vaultly-dl').forEach((b) => (b.onclick = async () => {
		try { await download(b.dataset.name); } catch (e) { show(e.message, 0, true); }
	}));
	$('#vaultly-copy').onclick = () => { const i = $('#vaultly-link-url'); i.select(); try { navigator.clipboard.writeText(i.value); } catch (e) { document.execCommand('copy'); } };
	$$('.vaultly-verify').forEach((b) => (b.onclick = () => job('verify', { archive: b.dataset.name })));
	// in-page confirmation (same popup as backup/download) instead of the browser's confirm() alert
	const ask = (text, okLabel) => new Promise((res) => {
		const [h, ...rest] = text.split('\n\n'), d = $('#vaultly-done');
		$('#vaultly-run').hidden = true;
		box.hidden = false;
		d.hidden = false;
		const yes = el('button', 'button button-primary', okLabel), no = el('button', 'button', T('cancelBtn')), row = el('div', 'vaultly-btns');
		const end = (v) => { box.hidden = true; d.hidden = true; res(v); };
		yes.onclick = () => end(true);
		no.onclick = () => end(false);
		row.append(yes, ' ', no);
		d.replaceChildren(el('h2', '', h), el('p', 'vaultly-sub', rest.join('\n\n')), row);
		no.focus();
	});
	$$('.vaultly-restore').forEach((b) => (b.onclick = async () => {
		const what = { full: T('scopeFull'), db: T('scopeDb'), files: T('scopeFiles') }[b.dataset.scope] || T('scopeSite');
		if (await ask(T('confirmRestore', b.dataset.name, what), T('restoreBtn'))) job('restore', { archive: b.dataset.name });
	}));
	$$('.vaultly-delete').forEach((b) => (b.onclick = async () => {
		if (!(await ask(T('confirmDelete', b.dataset.name), T('deleteBtn')))) return;
		try { await post('vaultly_delete', { name: b.dataset.name }); location.reload(); } catch (e) { show(e.message, 0, true); }
	}));

	// tabs (remember last one)
	const tab = (name) => {
		$$('.vaultly-tab').forEach((t) => t.classList.toggle('is-active', t.dataset.tab === name));
		$$('.vaultly-panel').forEach((p) => (p.hidden = p.id !== 'vaultly-tab-' + name));
		try { sessionStorage.setItem('vaultly-tab', name); } catch (e) { /* private mode */ }
	};
	$$('.vaultly-tab').forEach((t) => (t.onclick = () => tab(t.dataset.tab)));
	try { tab(sessionStorage.getItem('vaultly-tab') || 'backups'); } catch (e) { /* ignore */ }

	$('#vaultly-settings').onsubmit = async (e) => {
		e.preventDefault();
		const f = new FormData(e.target), d = Object.fromEntries(f.entries());
		e.target.querySelectorAll('input[type=checkbox]').forEach((c) => { d[c.name] = c.checked ? '1' : '0'; });
		const out = $('#vaultly-saved');
		try { await post('vaultly_settings', d); out.textContent = T('saved'); setTimeout(() => location.reload(), 600); } catch (x) { out.textContent = x.message; }
	};

	// A job is still running (page was reloaded / tab closed): keep driving it and show the popup again.
	if (C.resume) {
		lock(true);
		cancelled = false;
		t0 = Date.now();
		show(T('resuming'), 0);
		drive(C.resume.token).catch((e) => { head('', null); show(e.message, 0, true); lock(false); cancel.hidden = true; });
	}

})();
