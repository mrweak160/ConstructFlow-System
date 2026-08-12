// ConstructFlow — Frontend API Connector
// Replace the localStorage DB layer with real PHP backend calls.
// Place this file in the same folder as index.html.
// Set BASE_URL to your server's address.

const BASE_URL = './api'; // change to full URL if on a different server

const API = {

  // ── AUTH ──────────────────────────────────────────────────
  async login(email, password) {
    return post('auth.php?action=login', { email, password });
  },
  async logout() {
    return post('auth.php?action=logout', {});
  },
  async me() {
    return get('auth.php?action=me');
  },
  async changePassword(currentPassword, newPassword) {
    return post('auth.php?action=change_password', { current_password: currentPassword, new_password: newPassword });
  },

  // ── REPORTS ───────────────────────────────────────────────
  async submitReport(formData) {
    // formData is a FormData object (supports file upload)
    return postForm('reports.php?action=submit', formData);
  },
  async listReports(filters = {}) {
    const q = new URLSearchParams(filters).toString();
    return get(`reports.php?action=list${q ? '&' + q : ''}`);
  },
  async reportDetail(id) {
    return get(`reports.php?action=detail&id=${id}`);
  },
  async reportStats() {
    return get('reports.php?action=stats');
  },

  // ── WORK ORDERS ───────────────────────────────────────────
  async createWorkOrder(data) {
    return post('workorders.php?action=create', data);
  },
  async listWorkOrders(filters = {}) {
    const q = new URLSearchParams(filters).toString();
    return get(`workorders.php?action=list${q ? '&' + q : ''}`);
  },
  async workOrderDetail(id) {
    return get(`workorders.php?action=detail&id=${id}`);
  },
  async updateWorkOrderStatus(formData) {
    return postForm('workorders.php?action=update_status', formData);
  },
  async workOrderStats() {
    return get('workorders.php?action=stats');
  },

  // ── USERS ─────────────────────────────────────────────────
  async listUsers(filters = {}) {
    const q = new URLSearchParams(filters).toString();
    return get(`users.php?action=list${q ? '&' + q : ''}`);
  },
  async createUser(data) {
    return post('users.php?action=create', data);
  },
  async editUser(data) {
    return post('users.php?action=edit', data);
  },
  async resetPassword(userId, newPassword) {
    return post('users.php?action=reset_password', { user_id: userId, new_password: newPassword });
  },

  // ── LOGS ──────────────────────────────────────────────────
  async listLogs(limit = 50, offset = 0) {
    return get(`logs.php?action=list&limit=${limit}&offset=${offset}`);
  },
  async clearLogs() {
    return post('logs.php?action=clear', {});
  },
  exportLogs() {
    window.open(`${BASE_URL}/logs.php?action=export`, '_blank');
  },
};

// ── HTTP HELPERS ──────────────────────────────────────────────
async function get(endpoint) {
  try {
    const res = await fetch(`${BASE_URL}/${endpoint}`, {
      credentials: 'include',
    });
    return res.json();
  } catch (e) {
    return { success: false, message: 'Network error. Please check your connection.' };
  }
}

async function post(endpoint, body) {
  try {
    const res = await fetch(`${BASE_URL}/${endpoint}`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    return res.json();
  } catch (e) {
    return { success: false, message: 'Network error. Please check your connection.' };
  }
}

async function postForm(endpoint, formData) {
  // FormData automatically sets multipart/form-data header
  try {
    const res = await fetch(`${BASE_URL}/${endpoint}`, {
      method: 'POST',
      credentials: 'include',
      body: formData,
    });
    return res.json();
  } catch (e) {
    return { success: false, message: 'Network error. Please check your connection.' };
  }
}
