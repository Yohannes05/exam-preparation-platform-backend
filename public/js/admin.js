/* =========================================================================
 * Ethiopian National Exam Prep — Laravel Admin (vanilla JS SPA)
 * Plain JS, no build step. Talks to /api/* on the Laravel backend.
 * ========================================================================= */

(function () {
  "use strict";

  const TOKEN_KEY = "exam_admin_token";
  const USER_KEY = "exam_admin_user";

  /* ------------------------------ utils ------------------------------ */

  function getToken() {
    return typeof window !== "undefined" ? localStorage.getItem(TOKEN_KEY) : null;
  }

  function getUser() {
    if (typeof window === "undefined") return null;
    try {
      return JSON.parse(localStorage.getItem(USER_KEY) || "null");
    } catch {
      return null;
    }
  }

  function setSession(token, user) {
    localStorage.setItem(TOKEN_KEY, token);
    localStorage.setItem(USER_KEY, JSON.stringify(user));
  }

  function clearSession() {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
  }

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = text;
    return node;
  }

  function escapeHtml(str) {
    return String(str ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  async function request(path, opts) {
    const headers = { Accept: "application/json" };
    if (opts?.body) headers["Content-Type"] = "application/json";
    if (getToken()) headers.Authorization = "Bearer " + getToken();

    const res = await fetch("/api" + path, {
      method: opts?.method || "GET",
      headers,
      body: opts?.body != null ? JSON.stringify(opts.body) : undefined,
    });

    let data = null;
    try {
      data = await res.json();
    } catch {
      /* empty body */
    }

    if (res.status === 401 && path !== "/login") {
      clearSession();
      window.location.href = "/login";
      throw new Error("Session expired");
    }

    if (!res.ok) {
      const message = data?.message || "Request failed (" + res.status + ")";
      throw new Error(message);
    }

    return data;
  }

  const api = {
    get: (p, params) => request(p, { params }),
    post: (p, b) => request(p, { method: "POST", body: b }),
    put: (p, b) => request(p, { method: "PUT", body: b }),
    patch: (p, b) => request(p, { method: "PATCH", body: b }),
    del: (p) => request(p, { method: "DELETE" }),
  };

  function fieldErrors(err) {
    const out = {};
    if (err?.errors) {
      for (const [field, msgs] of Object.entries(err.errors)) {
        out[field] = Array.isArray(msgs) ? msgs[0] : msgs;
      }
    }
    return out;
  }

  /* ------------------------------ toast ------------------------------ */

  function showToast(type, message) {
    const holder = document.querySelector(".toast-holder");
    if (!holder) return;
    const t = el("div", "toast toast-" + type);
    t.textContent = message;
    holder.appendChild(t);
    setTimeout(() => {
      if (t.parentNode) t.parentNode.removeChild(t);
    }, 4000);
  }

  /* ---------------------------- router ----------------------------- */

  const routes = [];
  function route(path, component) {
    routes.push({ path, component });
  }

  function navigate(path) {
    window.history.pushState(null, "", path);
    render();
  }

  function render() {
    const root = document.getElementById("app");
    if (!root) return;

    const path = window.location.pathname;
    const match = routes.find((r) => path === r.path);

    if (match) {
      match.component();
    } else {
      route("/");
      root.innerHTML = '<div class="content text-gray-500">Page not found.</div>';
    }
  }

  /* ------------------------- login view ---------------------------- */

  function loginView() {
    const root = document.getElementById("app");
    root.innerHTML = `
      <div class="login-wrap">
        <div class="login-card">
          <div class="logo">
            <svg width="40" height="40" viewBox="0 0 44 40" fill="none" xmlns="http://www.w3.org/2000/svg">
              <rect width="44" height="40" rx="8" fill="#2563eb"/>
              <path d="M14 14 L22 14 L22 22 L14 22 Z" fill="#fff"/>
              <rect x="16" y="16" width="4" height="8" rx="1.5" fill="#2563eb"/>
              <rect x="22" y="14" width="4" height="12" rx="1.5" fill="#2563eb"/>
              <rect x="28" y="16" width="4" height="8" rx="1.5" fill="#2563eb"/>
              <path d="M22 10 L22 14" stroke="#fff" stroke-width="1.6" stroke-linecap="round"/>
              <path d="M14 18 L22 22" stroke="#fff" stroke-width="1.6" stroke-linecap="round"/>
            </svg>
          </div>
          <h1>Exam Prep Admin</h1>
          <p class="sub">Sign in to manage content and students</p>
          <form id="login-form" novalidate>
            <div class="error" id="login-error" hidden></div>
            <div class="form-group">
              <label>Email</label>
              <input type="email" id="email" autocomplete="email" required autofocus />
            </div>
            <div class="form-group">
              <label>Password</label>
              <input type="password" id="password" autocomplete="current-password" required />
            </div>
            <div class="form-actions">
              <button type="submit" class="btn btn-primary" id="login-submit">Sign in</button>
            </div>
          </form>
        </div>
      </div>
    `;

    const form = document.getElementById("login-form");
    const email = document.getElementById("email");
    const password = document.getElementById("password");
    const errorEl = document.getElementById("login-error");
    const submitBtn = document.getElementById("login-submit");

    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      errorEl.hidden = true;
      submitBtn.disabled = true;
      submitBtn.textContent = "Signing in…";

      try {
        const data = await api.post("/login", {
          email: email.value.trim(),
          password: password.value,
          device_name: "admin-dashboard",
        });
        setSession(data.token, data.user);
        showToast("success", "Signed in as " + data.user.name);
        navigate("/");
      } catch (err) {
        errorEl.textContent = err.message;
        errorEl.hidden = false;
      } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = "Sign in";
      }
    });
  }

  /* --------------------- generic resource page --------------------- */

  const DEFAULT_OPTIONS = [
    { label: "A", text: "", is_correct: true },
    { label: "B", text: "", is_correct: false },
    { label: "C", text: "", is_correct: false },
    { label: "D", text: "", is_correct: false },
  ];

  function defaultForm(fields) {
    const form = {};
    for (const f of fields) {
      if (f.type === "checkbox") form[f.name] = false;
      else if (f.type === "number") form[f.name] = "";
      else if (f.type === "options") form[f.name] = DEFAULT_OPTIONS.map((o) => ({ ...o }));
      else if (f.type === "ref") form[f.name] = "";
      else form[f.name] = "";
    }
    return form;
  }

  function rowToForm(row, fields) {
    const form = {};
    for (const f of fields) {
      const value = row[f.name];
      if (f.type === "checkbox") form[f.name] = !!value;
      else if (f.type === "options") form[f.name] = (value || []).map((o) => ({ ...o }));
      else if (f.type === "number") form[f.name] = value ?? "";
      else form[f.name] = value ?? "";
    }
    return form;
  }

  function refLabel(item) {
    return item ? (item.name || item.title || "#" + item.id) : "";
  }

  function resourceView(config) {
    const {
      resource,
      title,
      columns,
      fields,
      filters = [],
      searchable = true,
      searchPlaceholder = "Search…",
      emptyText = "Nothing here yet — create the first entry.",
    } = config;

    const root = document.getElementById("app");
    root.innerHTML = `
      <div class="app-page">
        <aside class="app-sidebar" id="sidebar">
          <div class="app-sidebar__brand">
            <svg width="28" height="28" viewBox="0 0 28 26" fill="none" xmlns="http://www.w3.org/2000/svg">
              <rect width="28" height="26" rx="6" fill="#2563eb"/>
              <path d="M9 7 L14 7 L14 13 L9 13 Z" fill="#fff"/>
              <rect x="10.6" y="10.6" width="3" height="5" rx="1.4" fill="#fff"/>
              <rect x="14" y="8" width="3" height="9" rx="1.4" fill="#fff"/>
              <rect x="17.4" y="10.6" width="3" height="5" rx="1.4" fill="#fff"/>
              <path d="M14 5 L14 9" stroke="#fff" stroke-width="1.6" stroke-linecap="round"/>
              <path d="M9 11 L14 15" stroke="#fff" stroke-width="1.6" stroke-linecap="round"/>
            </svg>
            <div>
              <div class="app-sidebar__brand-text">Ethiopian Exam Prep</div>
              <div class="app-sidebar__brand-sub">Admin Dashboard</div>
            </div>
          </div>
          <nav class="app-sidebar__nav">
            <div class="nav-section">Main</div>
            <a class="nav-link${window.location.pathname === "/" ? " active" : ""}" href="#" data-nav="/">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><path d="M3 17l4-4 4 4 8-8"/><path d="M16 7h5v5"/></svg>
              Dashboard
            </a>
            <a class="nav-link${window.location.pathname === "/students" ? " active" : ""}" href="#" data-nav="/students">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
              Students
            </a>
            <a class="nav-link${window.location.pathname === "/results" ? " active" : ""}" href="#" data-nav="/results">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="12" x2="8" y2="12"/><line x1="16" y1="16" x2="8" y2="16"/></svg>
              Results
            </a>
            <div class="nav-section">Content</div>
            <a class="nav-link${window.location.pathname === "/grades" ? " active" : ""}" href="#" data-nav="/grades">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><path d="M5 12h14"/></svg>
              Grades
            </a>
            <a class="nav-link${window.location.pathname === "/subjects" ? " active" : ""}" href="#" data-nav="/subjects">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M2 3h20v7H6a2 2 0 0 1 0-4h12a2 2 0 0 1 0 4Z"/><path d="M6 22V10"/><path d="M10 22V10"/><path d="M14 22V10"/><path d="M18 22V10"/></svg>
              Subjects
            </a>
            <a class="nav-link${window.location.pathname === "/chapters" ? " active" : ""}" href="#" data-nav="/chapters">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6 19.5A2.5 2.5 0 0 0 8.5 17H4"/><path d="M4 12.5A2.5 2.5 0 0 1 6.5 10H20"/><path d="M6 12.5A2.5 2.5 0 0 0 8.5 10H4"/><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20"/><path d="M6 5.5A2.5 2.5 0 0 0 8.5 3H4"/></svg>
              Chapters
            </a>
            <a class="nav-link${window.location.pathname === "/topics" ? " active" : ""}" href="#" data-nav="/topics">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 22V8"/><path d="M12 8L8 16l4 8 4-8z"/><circle cx="12" cy="2" r="1"/></svg>
              Topics
            </a>
            <a class="nav-link${window.location.pathname === "/notes" ? " active" : ""}" href="#" data-nav="/notes">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6 19.5A2.5 2.5 0 0 0 8.5 17H4"/><path d="M4 12.5A2.5 2.5 0 0 1 6.5 10H20"/><path d="M6 12.5A2.5 2.5 0 0 0 8.5 10H4"/><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20"/><path d="M6 5.5A2.5 2.5 0 0 0 8.5 3H4"/></svg>
              Notes
            </a>
            <a class="nav-link${window.location.pathname === "/questions" ? " active" : ""}" href="#" data-nav="/questions">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v8"/><path d="M8 12h8"/></svg>
              Questions
            </a>
            <a class="nav-link${window.location.pathname === "/exams" ? " active" : ""}" href="#" data-nav="/exams">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/></svg>
              Exams
            </a>
            <a class="nav-link${window.location.pathname === "/announcements" ? " active" : ""}" href="#" data-nav="/announcements">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 20.5V4"/><path d="M12 4h.01"/><path d="M4.93 4.93l14.14 14.14"/><path d="M19.07 4.93l-14.14 14.14"/></svg>
              Announcements
            </a>
          </nav>
          <div class="app-sidebar__bottom">
            <div class="user-chip">
              <span>${escapeHtml(getUser()?.name || "Admin")}</span>
              <span class="text-xs text-gray-400">·</span>
              <button class="btn-ghost btn-sm" id="logout-btn">Log out</button>
            </div>
          </div>
        </aside>

        <div class="app-page__main">
          <header class="app-header">
            <h1 class="app-header__title">${title}</h1>
            <div class="app-header__actions">
              <div class="user-chip">
                <span>${escapeHtml(getUser()?.name || "Admin")}</span>
                <button class="btn-ghost btn-sm" id="logout-btn2">Log out</button>
              </div>
            </div>
          </header>
          <main class="content">
            <div class="card">
              <div class="card__head">
                <h2>${title}</h2>
                <div class="card__head-actions">
                  <button class="btn btn-primary" id="new-btn">+ New</button>
                </div>
              </div>
              <div class="table-wrap">
                <table id="table-${resource}">
                  <thead>
                    <tr>
                      ${columns.map((c) => `<th>${escapeHtml(c.label)}</th>`).join("")}
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody></tbody>
                </table>
              </div>
              <div class="pagination" id="pagination-${resource}"></div>
            </div>
          </main>
        </div>
      </div>
    `;

    const state = {
      rows: [],
      meta: { page: 1, last: 1, total: 0 },
      page: 1,
      search: "",
      filterValues: {},
      editing: null,
      form: {},
      formErrors: {},
      saving: false,
      refData: {},
      loading: false,
      error: "",
      resource,
      fields,
      columns,
      filters,
      searchable,
    };

    const refs = {};

    function loadRefs() {
      if (Object.keys(refs).length) return Promise.resolve();
      state.loading = true;
      state.error = "";
      return Promise.all(
        refSources(fields, filters).map((s) =>
          api.get(`/v1/${s}`, { per_page: 100 }).then((d) => {
            refs[s] = d.data || [];
          })
        )
      ).catch(() => {}).finally(() => {
        state.loading = false;
      });
    }

    function refSources(fields, filters) {
      const sources = new Set();
      fields.forEach((f) => { if (f.source) sources.add(f.source); });
      filters.forEach((f) => { if (f.source) sources.add(f.source); });
      return [...sources];
    }

    function load(page) {
      state.loading = true;
      state.error = "";
      const params = { page: page || state.page, per_page: 15 };
      if (state.searchable) params.search = state.search;
      Object.keys(state.filterValues).forEach((k) => {
        if (state.filterValues[k] !== "") params[k] = state.filterValues[k];
      });
      return fetch(`/api/v1/${resource}?` + new URLSearchParams(params))
        .then((r) => r.json())
        .then((data) => {
          state.rows = data.data;
          state.meta = { page: data.current_page, last: data.last_page, total: data.total };
          render();
        })
        .catch((e) => {
          state.error = e.message;
          render();
        })
        .finally(() => {
          state.loading = false;
        });
    }

    function render() {
      const tbody = document.querySelector(`#table-${resource} tbody`);
      const pg = document.getElementById(`pagination-${resource}`);
      if (!tbody) return;

      if (state.loading) {
        tbody.innerHTML = `<tr><td colspan="${columns.length + 1}"><div class="empty">Loading…</div></td></tr>`;
        return;
      }
      if (state.error) {
        tbody.innerHTML = `<tr><td colspan="${columns.length + 1}"><div class="empty">${escapeHtml(state.error)}</div></td></tr>`;
        return;
      }
      if (state.rows.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${columns.length + 1}"><div class="empty">${escapeHtml(emptyText)}</div></td></tr>`;
        return;
      }
      tbody.innerHTML = state.rows
        .map((row) => {
          const cells = columns
            .map((c) => {
              const value = c.render ? c.render(row) : row[c.key];
              return `<td>${typeof value === "string" ? escapeHtml(value) : value || "—"}</td>`;
            })
            .join("");
          return `<tr class="row-${row.id}">${cells}<td>
            <button class="btn btn-ghost btn-sm edit-btn" data-id="${row.id}">Edit</button>
            <button class="btn btn-ghost btn-sm btn-danger delete-btn" data-id="${row.id}">Delete</button>
          </td></tr>`;
        })
        .join("");

      tbody.querySelectorAll(".edit-btn").forEach((btn) => {
        btn.addEventListener("click", () => openEdit(parseInt(btn.dataset.id, 10)));
      });
      tbody.querySelectorAll(".delete-btn").forEach((btn) => {
        btn.addEventListener("click", () => removeRow(parseInt(btn.dataset.id, 10)));
      });

      if (pg) {
        pg.innerHTML = "";
        if (state.meta.last > 1) {
          const prev = el("button", "btn btn-secondary btn-sm");
          prev.textContent = "← Previous";
          prev.disabled = state.page <= 1;
          prev.addEventListener("click", () => {
            state.page--;
            load();
          });
          pg.appendChild(prev);

          const count = el("span");
          count.textContent = `Page ${state.meta.page} of ${state.meta.last}`;
          pg.appendChild(count);

          const next = el("button", "btn btn-secondary btn-sm");
          next.textContent = "Next →";
          next.disabled = state.page >= state.meta.last;
          next.addEventListener("click", () => {
            state.page++;
            load();
          });
          pg.appendChild(next);
        }
      }
    }

    function openCreate() {
      state.editing = null;
      state.form = defaultForm(fields);
      state.formErrors = {};
      state.error = "";
      showModal();
    }

    function openEdit(id) {
      state.editing = id;
      state.formErrors = {};
      state.error = "";
      api
        .get(`/api/v1/${resource}/${id}`)
        .then((full) => {
          state.form = rowToForm(full, fields);
          showModal();
        })
        .catch((e) => {
          state.error = e.message;
          render();
        });
    }

    function showModal() {
      const wrap = el("div");
      wrap.className = "modal-backdrop";
      wrap.innerHTML = `
        <div class="modal">
          <div class="modal-head">
            <h3>${state.editing === null ? "New " + title : "Edit " + title}</h3>
            <button class="btn-ghost btn-sm" id="modal-close">✕</button>
          </div>
          <form class="modal-body" id="modal-form">
            <div class="form-grid form-grid--2">
              ${fields
                .map((f) => {
                  const wide = f.wide ? "md:col-span-2" : "";
                  let input = "";
                  if (f.type === "options") {
                    input = `<div class="space-y-2" id="options-wrap"></div>`;
                  } else if (f.type === "select") {
                    input = `<select class="input" id="f-${f.name}"><option value="">— select —</option>` +
                      (f.options || [])
                        .map((o) => `<option value="${o.value}">${escapeHtml(o.label)}</option>`)
                        .join("") +
                      `</select>`;
                  } else if (f.type === "ref") {
                    input = `<select class="input" id="f-${f.name}"><option value="">— select —</option>` +
                      (refs[f.source] || [])
                        .map((o) => `<option value="${o.id}">${escapeHtml(refLabel(o))}</option>`)
                        .join("") +
                      `</select>`;
                  } else if (f.type === "checkbox") {
                    input = `<label class="flex items-center gap-2 text-sm text-gray-700">` +
                      `<input type="checkbox" id="f-${f.name}" class="rounded border-gray-300" /> ` +
                      escapeHtml(f.label) + `</label>`;
                  } else {
                    const val = state.form[f.name] ?? "";
                    const escaped = typeof val === "string" ? escapeHtml(val) : val ?? "";
                    input = `<input class="input" id="f-${f.name}" value="${escaped}" />`;
                  }
                  return `<div class="form-group"><label>${escapeHtml(f.label)}${f.required ? ' <span class="text-red-500">*</span>' : ''}</label>${input}</div>`;
                })
                .join("")}
            </div>
            ${state.error ? `<div class="error" style="margin-top:12px;">${escapeHtml(state.error)}</div>` : ""}
            <div class="modal-foot">
              <button type="button" class="btn btn-secondary" id="modal-close2">Cancel</button>
              <button type="submit" class="btn btn-primary" id="modal-save">Save</button>
            </div>
          </form>
        </div>
      `;
      document.body.appendChild(wrap);

      document.getElementById("modal-close").addEventListener("click", closeModal);
      document.getElementById("modal-close2").addEventListener("click", closeModal);
      document.getElementById("modal-form").addEventListener("submit", save);

      // Populate selects
      fields.forEach((f) => {
        if (f.type === "ref") {
          const sel = document.getElementById(`f-${f.name}`);
          if (sel) {
            (refs[f.source] || []).forEach((o) => {
              const opt = document.createElement("option");
              opt.value = o.id;
              opt.textContent = refLabel(o);
              sel.appendChild(opt);
            });
          }
        }
        if (f.type === "checkbox") {
          const chk = document.getElementById(`f-${f.name}`);
          if (chk) chk.checked = !!state.form[f.name];
        }
      });

      function save(e) {
        e.preventDefault();
        state.error = "";
        state.saving = true;
        const payload = {};
        fields.forEach((f) => {
          let value = state.form[f.name];
          if (f.type === "number") value = state.form[f.name] === "" ? null : Number(state.form[f.name]);
          if (f.type === "ref") value = state.form[f.name] === "" ? null : Number(state.form[f.name]);
          payload[f.name] = value;
        });
        const method = state.editing === null ? "POST" : "PUT";
        const url = state.editing === null
          ? `/api/v1/${resource}`
          : `/api/v1/${resource}/${state.editing}`;
        fetch(url, {
          method,
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload),
        })
          .then((r) => r.json().then((d) => ({ ok: r.ok, data: d })))
          .then((res) => {
            if (!res.ok) throw new Error(res.data?.message || "Something went wrong");
            closeModal();
            load();
            showToast("success", state.editing === null ? "Created" : "Updated");
          })
          .catch((e) => {
            state.error = e.message;
            showFormErrors(fieldErrors(e));
          })
          .finally(() => {
            state.saving = false;
          });
      }

      function showFormErrors(errors) {
        if (!errors || !Object.keys(errors).length) return;
        document.querySelectorAll(`#modal-${resource} .form-group`).forEach((fg) => {
          const label = fg.querySelector("label");
          if (!label) return;
          const name = label.textContent.trim().toLowerCase();
          if (errors[name]) {
            const hint = fg.querySelector(".hint");
            if (hint) hint.remove();
            const err = document.createElement("p");
            err.className = "hint text-red-600 text-xs";
            err.textContent = errors[name];
            fg.appendChild(err);
          }
        });
      }

      function closeModal() {
        state.editing = null;
        state.form = {};
        state.formErrors = {};
        state.error = "";
        const wrap = document.getElementById(`modal-${resource}`);
        if (wrap) wrap.innerHTML = "";
      }

      function removeRow(id) {
        if (!window.confirm("Delete this entry?")) return;
        api
          .del(`/api/v1/${resource}/${id}`)
          .then(() => {
            load();
            showToast("success", "Deleted");
          })
          .catch((e) => {
            state.error = e.message;
            render();
          });
      }
    }

    // Wiring
    document.getElementById("new-btn").addEventListener("click", openCreate);

    const searchInput = document.getElementById("search");
    if (searchInput) {
      searchInput.addEventListener("input", () => {
        state.search = searchInput.value;
        state.page = 1;
        load(1);
      });
    }

    // Filters
    document.querySelectorAll(`[id^="filter-"]`).forEach((sel) => {
      sel.addEventListener("change", () => {
        state.filterValues[sel.id.replace("filter-", "")] = sel.value;
        state.page = 1;
        load(1);
      });
    });

    document.addEventListener("click", (e) => {
      if (e.target.closest(".modal-backdrop")) closeModal();
    });

    document.getElementById("logout-btn")?.addEventListener("click", () => {
      api
        .post("/logout")
        .then(() => {
          clearSession();
          navigate("/login");
        })
        .catch(() => {
          clearSession();
          navigate("/login");
        });
    });
    document.getElementById("logout-btn2")?.addEventListener("click", () => {
      api
        .post("/logout")
        .then(() => {
          clearSession();
          navigate("/login");
        })
        .catch(() => {
          clearSession();
          navigate("/login");
        });
    });

    loadRefs().then(() => load(1));
  }

  /* ------------------------ admin pages ------------------------ */

  route("/login", loginView);

  function dashboardView() {
    const root = document.getElementById("app");
    root.innerHTML = `
      <div class="app-page">
        <aside class="app-sidebar" id="sidebar">
          <div class="app-sidebar__brand">
            <svg width="28" height="28" viewBox="0 0 28 26" fill="none" xmlns="http://www.w3.org/2000/svg">
              <rect width="28" height="26" rx="6" fill="#2563eb"/>
              <path d="M9 7 L14 7 L14 13 L9 13 Z" fill="#fff"/>
              <rect x="10.6" y="10.6" width="3" height="5" rx="1.4" fill="#fff"/>
              <rect x="14" y="8" width="3" height="9" rx="1.4" fill="#fff"/>
              <rect x="17.4" y="10.6" width="3" height="5" rx="1.4" fill="#fff"/>
              <path d="M14 5 L14 9" stroke="#fff" stroke-width="1.6" stroke-linecap="round"/>
              <path d="M9 11 L14 15" stroke="#fff" stroke-width="1.6" stroke-linecap="round"/>
            </svg>
            <div>
              <div class="app-sidebar__brand-text">Ethiopian Exam Prep</div>
              <div class="app-sidebar__brand-sub">Admin Dashboard</div>
            </div>
          </div>
          <nav class="app-sidebar__nav">
            <div class="nav-section">Main</div>
            <a class="nav-link active" href="#" data-nav="/">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><path d="M3 17l4-4 4 4 8-8"/><path d="M16 7h5v5"/></svg>
              Dashboard
            </a>
            <a class="nav-link" href="#" data-nav="/students">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
              Students
            </a>
            <a class="nav-link" href="#" data-nav="/results">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="12" x2="8" y2="12"/><line x1="16" y1="16" x2="8" y2="16"/></svg>
              Results
            </a>
            <div class="nav-section">Content</div>
            <a class="nav-link" href="#" data-nav="/grades">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><path d="M5 12h14"/></svg>
              Grades
            </a>
            <a class="nav-link" href="#" data-nav="/subjects">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M2 3h20v7H6a2 2 0 0 1 0-4h12a2 2 0 0 1 0 4Z"/><path d="M6 22V10"/><path d="M10 22V10"/><path d="M14 22V10"/><path d="M18 22V10"/></svg>
              Subjects
            </a>
            <a class="nav-link" href="#" data-nav="/chapters">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6 19.5A2.5 2.5 0 0 0 8.5 17H4"/><path d="M4 12.5A2.5 2.5 0 0 1 6.5 10H20"/><path d="M6 12.5A2.5 2.5 0 0 0 8.5 10H4"/><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20"/><path d="M6 5.5A2.5 2.5 0 0 0 8.5 3H4"/></svg>
              Chapters
            </a>
            <a class="nav-link" href="#" data-nav="/topics">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 22V8"/><path d="M12 8L8 16l4 8 4-8z"/><circle cx="12" cy="2" r="1"/></svg>
              Topics
            </a>
            <a class="nav-link" href="#" data-nav="/notes">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6 19.5A2.5 2.5 0 0 0 8.5 17H4"/><path d="M4 12.5A2.5 2.5 0 0 1 6.5 10H20"/><path d="M6 12.5A2.5 2.5 0 0 0 8.5 10H4"/><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20"/><path d="M6 5.5A2.5 2.5 0 0 0 8.5 3H4"/></svg>
              Notes
            </a>
            <a class="nav-link" href="#" data-nav="/questions">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v8"/><path d="M8 12h8"/></svg>
              Questions
            </a>
            <a class="nav-link" href="#" data-nav="/exams">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/></svg>
              Exams
            </a>
            <a class="nav-link" href="#" data-nav="/announcements">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 20.5V4"/><path d="M12 4h.01"/><path d="M4.93 4.93l14.14 14.14"/><path d="M19.07 4.93l-14.14 14.14"/></svg>
              Announcements
            </a>
          </nav>
          <div class="app-sidebar__bottom">
            <div class="user-chip">
              <span>Admin</span>
              <span class="text-xs text-gray-400">·</span>
              <button class="btn-ghost btn-sm" id="logout-btn">Log out</button>
            </div>
          </div>
        </aside>
        <div class="app-page__main">
          <header class="app-header">
            <h1 class="app-header__title">Dashboard</h1>
            <div class="app-header__actions">
              <div class="user-chip">
                <span>Admin</span>
                <button class="btn-ghost btn-sm" id="logout-btn2">Log out</button>
              </div>
            </div>
          </header>
          <main class="content">
            <div class="card">
              <div class="card__head">
                <h2>Dashboard overview</h2>
                <div class="card__head-actions">
                  <button class="btn btn-primary btn-sm" id="new-btn">+ New</button>
                </div>
              </div>
              <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <div class="card">
                  <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Students</div>
                  <div class="mt-1 text-2xl font-bold text-gray-900" id="stat-students">—</div>
                  <div class="mt-1 text-xs text-gray-400" id="stat-students-hint">Loading…</div>
                </div>
                <div class="card">
                  <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Questions</div>
                  <div class="mt-1 text-2xl font-bold text-gray-900" id="stat-questions">—</div>
                  <div class="mt-1 text-xs text-gray-400" id="stat-questions-hint">Loading…</div>
                </div>
                <div class="card">
                  <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Questions answered</div>
                  <div class="mt-1 text-2xl font-bold text-gray-900" id="stat-answered">—</div>
                  <div class="mt-1 text-xs text-gray-400" id="stat-answered-hint">Loading…</div>
                </div>
                <div class="card">
                  <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Exams completed</div>
                  <div class="mt-1 text-2xl font-bold text-gray-900" id="stat-exams">—</div>
                  <div class="mt-1 text-xs text-gray-400" id="stat-exams-hint">Loading…</div>
                </div>
              </div>
              <div class="grid gap-4 mt-4 lg:grid-cols-2">
                <div class="card">
                  <h2 class="mb-3 text-sm font-semibold text-gray-800">Popular subjects</h2>
                  <ul id="popular-subjects"></ul>
                </div>
                <div class="card">
                  <h2 class="mb-3 text-sm font-semibold text-gray-800">Difficult questions</h2>
                  <ul id="difficult-questions"></ul>
                </div>
              </div>
            </div>
          </main>
        </div>
      </div>
    `;

    api
      .get("/dashboard")
      .then((data) => {
        const s = data.students || {};
        document.getElementById("stat-students").textContent = s.total ?? "—";
        document.getElementById("stat-students-hint").textContent = s.new_this_week ? s.new_this_week + " new this week" : "";

        const c = data.content || {};
        document.getElementById("stat-questions").textContent = c.questions ?? "—";
        document.getElementById("stat-questions-hint").textContent = c.announcements ? c.announcements + " announcements" : "";

        const a = data.activity || {};
        document.getElementById("stat-answered").textContent = a.questions_answered ?? "—";
        const acc = a.accuracy;
        document.getElementById("stat-answered-hint").textContent = acc != null ? acc + "% average accuracy" : "Loading…";

        const ex = (a.mock_exams_completed || 0) + (a.chapter_tests_completed || 0);
        document.getElementById("stat-exams").textContent = ex + " done";
        document.getElementById("stat-exams-hint").textContent = a.mock_exams_completed
          ? a.mock_exams_completed + " mocks"
          : "";

        const pop = document.getElementById("popular-subjects");
        pop.innerHTML = (data.popular_subjects || []).map((s) => {
          const acc = s.accuracy;
          return `<li class="flex items-center justify-between text-sm">
            <span class="text-gray-700">${s.name}</span>
            <span class="text-gray-500">${s.answers} answers · ${acc != null ? acc + "%" : "—"}</span>
          </li>`;
        }).join("");

        const diff = document.getElementById("difficult-questions");
        diff.innerHTML = (data.difficult_questions || []).map((q) => {
          const acc = q.accuracy;
          return `<li class="text-sm">
            <div class="text-gray-700">${q.question_text.length > 110 ? q.question_text.slice(0, 110) + "…" : q.question_text}</div>
            <div class="text-xs text-red-500">${acc != null ? acc + "% correct over " + q.attempts + " attempts" : "—"}</div>
          </li>`;
        }).join("");
      })
      .catch((e) => {
        const content = document.querySelector(".content");
        if (content) content.innerHTML = '<div class="card"><div class="empty">' + escapeHtml(e.message) + "</div></div>";
      });
  }

  route("/", dashboardView);

  /* --------------------------- init -------------------------------- */

  function init() {
    const toggle = el("button", "app-sidebar__toggle btn btn-ghost btn-sm fixed top-4 left-4 z-50 lg:hidden");
    toggle.textContent = "☰ Menu";
    document.querySelector(".app-sidebar")?.insertAdjacentElement("afterbegin", toggle);
    toggle.addEventListener("click", () => {
      document.querySelector(".app-sidebar").classList.toggle("open");
    });

    if (getToken() && window.location.pathname === "/login") {
      navigate("/");
    } else if (!getToken() && window.location.pathname !== "/login" && !window.location.pathname.startsWith("/api")) {
      navigate("/login");
    }

    render();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
