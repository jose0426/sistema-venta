const defaultState = {
  products: [],
  cart: [],
  sales: []
};

const state = structuredClone(defaultState);
state.summary = { totalSales: 0, totalRevenue: 0, totalClients: 0 };

const searchInput = document.querySelector('#searchInput');
const categoryFilters = document.querySelector('#categoryFilters');
const productGrid = document.querySelector('#productGrid');
const cartItems = document.querySelector('#cartItems');
const inventoryTable = document.querySelector('#inventoryTable');
const salesTable = document.querySelector('#salesTable');
const productForm = document.querySelector('#productForm');
const productName = document.querySelector('#productName');
const productCategory = document.querySelector('#productCategory');
const productPrice = document.querySelector('#productPrice');
const productStock = document.querySelector('#productStock');
const latestProducts = document.querySelector('#latestProducts');
const cashBox = document.querySelector('#cashBox');
const totalRevenue = document.querySelector('#totalRevenue');
const totalSales = document.querySelector('#totalSales');
const totalProducts = document.querySelector('#totalProducts');
const totalClients = document.querySelector('#totalClients');
const subtotalValue = document.querySelector('#subtotalValue');
const discountRate = document.querySelector('#discountRate');
const discountValue = document.querySelector('#discountValue');
const totalValue = document.querySelector('#totalValue');
const customerName = document.querySelector('#customerName');
const paymentMethod = document.querySelector('#paymentMethod');
const cashReceived = document.querySelector('#cashReceived');
const changeValue = document.querySelector('#changeValue');
const checkoutButton = document.querySelector('#checkoutButton');
const cartCount = document.querySelector('#cartCount');
const newSaleButton = document.querySelector('#newSaleButton');
const navigationLinks = document.querySelectorAll('.sidebar-nav .nav-item');
const pageViews = document.querySelectorAll('.page-view');
const invoiceDialog = document.querySelector('#invoiceDialog');
const invoiceContent = document.querySelector('#invoiceContent');
const closeInvoiceButton = document.querySelector('#closeInvoiceButton');
const printInvoiceButton = document.querySelector('#printInvoiceButton');
const authScreen = document.querySelector('#authScreen');
const authCard = document.querySelector('#authCard');
const appShell = document.querySelector('#appShell');
const loggedInUser = document.querySelector('#loggedInUser');
const logoutButton = document.querySelector('#logoutButton');

let csrfToken = '';
let currentRole = '';

const currency = value => new Intl.NumberFormat('es-ES', {
  style: 'currency',
  currency: 'USD'
}).format(value);

async function apiRequest(action, method = 'GET', body = null) {
  const headers = body ? { 'Content-Type': 'application/json' } : {};
  if (method === 'POST' && csrfToken) {
    headers['X-CSRF-Token'] = csrfToken;
  }
  const response = await fetch(`api.php?action=${encodeURIComponent(action)}`, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined
  });
  const responseBody = await response.text();
  let result;
  try {
    result = JSON.parse(responseBody);
  } catch {
    throw new Error(
      `El servidor devolvió una respuesta no válida (HTTP ${response.status}). Revisa los errores de PHP y la configuración del servidor.`
    );
  }
  if (!response.ok) {
    if (response.status === 403 && result.error === 'La sesión venció o la solicitud no es válida. Recarga e inicia sesión nuevamente.') {
      window.location.reload();
    }
    throw new Error(result.error || 'No se pudo completar la solicitud.');
  }
  return result;
}

function renderAuthScreen(needsSetup, message = '') {
  authScreen.hidden = false;
  appShell.hidden = true;
  authCard.classList.remove('is-logging-in');
  authCard.innerHTML = needsSetup
    ? `
      <p class="eyebrow">Configuración segura</p>
      <h1>Crear cuenta administradora</h1>
      <p>Configura la cuenta inicial. Solo se puede crear una cuenta administradora.</p>
      <form class="auth-form" id="authForm">
        <input type="hidden" name="action" value="setup" />
        <label for="authUsername">Usuario</label>
        <input id="authUsername" name="username" type="text" minlength="3" maxlength="80" autocomplete="username" required />
        <label for="authPassword">Contraseña (mínimo 6 caracteres)</label>
        <input id="authPassword" name="password" type="password" minlength="6" maxlength="255" autocomplete="new-password" required />
        <label for="authPasswordConfirm">Confirmar contraseña</label>
        <input id="authPasswordConfirm" name="passwordConfirm" type="password" minlength="6" maxlength="255" autocomplete="new-password" required />
        <p class="auth-error" id="authError" role="alert">${escapeHtml(message)}</p>
        <button class="primary-btn" type="submit">Crear cuenta y entrar</button>
      </form>
    `
    : `
      <p class="eyebrow">Acceso protegido</p>
      <h1>Iniciar sesión</h1>
      <p>Ingresa con la cuenta administradora configurada para este sistema.</p>
      <form class="auth-form" id="authForm">
        <input type="hidden" name="action" value="login" />
        <label for="authUsername">Usuario</label>
        <input id="authUsername" name="username" type="text" maxlength="80" autocomplete="username" required />
        <label for="authPassword">Contraseña</label>
        <input id="authPassword" name="password" type="password" maxlength="255" autocomplete="current-password" required />
        <p class="auth-error" id="authError" role="alert">${escapeHtml(message)}</p>
        <button class="primary-btn" type="submit">Entrar</button>
      </form>
    `;

  authCard.querySelector('#authForm').addEventListener('submit', handleAuthSubmit);
}

async function showAuthAnimation(eyebrow, title, message, status) {
  authScreen.hidden = false;
  appShell.hidden = true;
  authCard.classList.add('is-logging-in');
  authCard.innerHTML = `
    <p class="eyebrow">${escapeHtml(eyebrow)}</p>
    <h1>${escapeHtml(title)}</h1>
    <p>${escapeHtml(message)}</p>
    <div class="login-progress" aria-hidden="true"><span></span></div>
    <p class="login-status" role="status">${escapeHtml(status)}</p>
  `;
  await new Promise(resolve => window.setTimeout(resolve, 5000));
}

async function handleAuthSubmit(event) {
  event.preventDefault();
  const form = event.currentTarget;
  const formData = new FormData(form);
  const action = formData.get('action');
  const body = Object.fromEntries(formData.entries());
  delete body.action;
  const submitButton = form.querySelector('button[type="submit"]');
  submitButton.disabled = true;

  try {
    const result = await apiRequest(action, 'POST', body);
    csrfToken = result.csrfToken;
    const serverState = await apiRequest('state');
    if (action === 'login') {
      await showAuthAnimation(
        'Acceso confirmado',
        `¡Bienvenido, ${result.username}!`,
        'Estamos preparando tu espacio de trabajo.',
        'Iniciando el sistema...'
      );
    }
    await enterApplication(result.username, result.role, serverState);
  } catch (error) {
    const errorMessage = authCard.querySelector('#authError');
    if (errorMessage) errorMessage.textContent = error.message;
    submitButton.disabled = false;
  }
}

async function enterApplication(username, role, serverState = null) {
  serverState = serverState || await apiRequest('state');
  applyServerState(serverState);
  currentRole = role;
  appShell.dataset.role = role;
  loggedInUser.textContent = username;
  navigationLinks.forEach(link => {
    const restricted = role !== 'admin' && link.hash !== '#venta' && link.hash !== '#dashboard';
    link.hidden = restricted;
  });
  authScreen.hidden = true;
  appShell.hidden = false;
  if (role !== 'admin' && window.location.hash !== '#venta') {
    window.location.hash = '#venta';
  }
  renderAll();
  renderActiveView();
}

async function handleLogout() {
  logoutButton.disabled = true;
  try {
    const result = await apiRequest('logout', 'POST', {});
    csrfToken = result.csrfToken;
    state.products = [];
    state.sales = [];
    state.cart = [];
    currentRole = '';
    delete appShell.dataset.role;
    navigationLinks.forEach(link => {
      link.hidden = false;
    });
    await showAuthAnimation(
      'Sesión cerrada',
      '¡Hasta pronto!',
      'Tu cuenta salió de forma segura.',
      'Volviendo al inicio de sesión...'
    );
    renderAuthScreen(false);
  } catch (error) {
    alert(error.message);
  } finally {
    logoutButton.disabled = false;
  }
}

function applyServerState(serverState) {
  state.products = serverState.products;
  state.sales = serverState.sales;
  state.summary = serverState.summary || {
    totalSales: state.sales.length,
    totalRevenue: state.sales.reduce((sum, sale) => sum + Number(sale.total || 0), 0),
    totalClients: new Set(state.sales.map(sale => sale.customer)).size
  };
}

function getCategories() {
  return ['Todos', ...new Set(state.products.map(product => product.category))];
}

function getFilteredProducts() {
  const query = searchInput.value.trim().toLowerCase();
  const selectedCategory = categoryFilters.dataset.selected || 'Todos';

  return state.products.filter(product => {
    const matchesCategory = selectedCategory === 'Todos' || product.category === selectedCategory;
    const matchesSearch = !query || product.name.toLowerCase().includes(query) || product.sku.toLowerCase().includes(query);
    return matchesCategory && matchesSearch;
  });
}

function renderFilters() {
  const categories = getCategories();
  categoryFilters.innerHTML = categories.map(category => `
    <button type="button" class="filter-chip ${category === (categoryFilters.dataset.selected || 'Todos') ? 'active' : ''}" data-category="${escapeHtml(category)}">
      ${escapeHtml(category)}
    </button>
  `).join('');

  categoryFilters.querySelectorAll('.filter-chip').forEach(button => {
    button.addEventListener('click', () => {
      categoryFilters.dataset.selected = button.dataset.category;
      renderFilters();
      renderProducts();
    });
  });
}

function renderProducts() {
  const products = getFilteredProducts();

  if (!products.length) {
    const message = state.products.length
      ? 'No se encontraron productos.'
      : 'Aún no hay productos. Regístralos en Inventario.';
    productGrid.innerHTML = `<div class="empty-cart"><p>${message}</p></div>`;
    return;
  }

  productGrid.innerHTML = products.map(product => `
    <article class="product-card">
      <div class="emoji">${escapeHtml(product.icon)}</div>
      <div>
        <h4>${escapeHtml(product.name)}</h4>
        <div class="meta-line">
          <span>${escapeHtml(product.category)}</span>
          <span>SKU: ${escapeHtml(product.sku)}</span>
        </div>
      </div>
      <div class="meta-line">
        <span>Stock: ${product.stock}</span>
        <span class="product-price">${currency(product.price)}</span>
      </div>
      <button type="button" class="add-btn" data-product-id="${product.id}" ${product.stock <= 0 ? 'disabled' : ''}>
        ${product.stock <= 0 ? 'Sin stock' : 'Agregar'}
      </button>
    </article>
  `).join('');

  productGrid.querySelectorAll('.add-btn').forEach(button => {
    button.addEventListener('click', () => addToCart(Number(button.dataset.productId)));
  });
}

function addToCart(productId) {
  const product = state.products.find(item => item.id === productId);
  if (!product || product.stock <= 0) return;

  const existingLine = state.cart.find(item => item.id === productId);

  if (existingLine) {
    if (existingLine.quantity >= product.stock) {
      alert('No hay suficiente stock disponible para este producto.');
      return;
    }
    existingLine.quantity += 1;
  } else {
    state.cart.push({
      id: product.id,
      name: product.name,
      price: product.price,
      quantity: 1,
      icon: product.icon
    });
  }

  renderCart();
  renderDashboard();
}

function adjustQuantity(productId, delta) {
  const line = state.cart.find(item => item.id === productId);
  if (!line) return;

  const product = state.products.find(item => item.id === productId);
  const nextQuantity = line.quantity + delta;

  if (nextQuantity <= 0) {
    state.cart = state.cart.filter(item => item.id !== productId);
  } else {
    if (product && nextQuantity > product.stock) {
      alert('La cantidad supera el stock disponible.');
      return;
    }
    line.quantity = nextQuantity;
  }

  renderCart();
  renderDashboard();
}

function getCartSummary() {
  const subtotal = state.cart.reduce((total, item) => total + (item.price * item.quantity), 0);
  const selectedDiscountRate = Number(discountRate.value);
  const discount = Math.round((subtotal * selectedDiscountRate / 100 + Number.EPSILON) * 100) / 100;
  const total = subtotal - discount;
  return { subtotal, discount, selectedDiscountRate, total };
}

function renderCart() {
  if (!state.cart.length) {
    cartItems.innerHTML = '<div class="empty-cart"><div><h4>Tu carrito está vacío</h4><p>Agrega productos para iniciar una venta.</p></div></div>';
  } else {
    cartItems.innerHTML = state.cart.map(item => `
      <div class="cart-item">
        <div class="cart-product-image" aria-hidden="true">${item.icon}</div>
        <div class="cart-product-info">
          <h4>${escapeHtml(item.name)}</h4>
          <small class="cart-unit-price">${currency(item.price)} por unidad</small>
          <div class="cart-item-actions">
            <div class="qty-controls" aria-label="Cantidad de ${escapeHtml(item.name)}">
              <button class="qty-btn" type="button" data-action="decrease" data-product-id="${item.id}" aria-label="Disminuir cantidad">−</button>
              <strong>${item.quantity}</strong>
              <button class="qty-btn" type="button" data-action="increase" data-product-id="${item.id}" aria-label="Aumentar cantidad">+</button>
            </div>
            <span class="cart-action-divider" aria-hidden="true"></span>
            <button class="remove-cart-item" type="button" data-action="remove" data-product-id="${item.id}">Eliminar</button>
          </div>
        </div>
        <div class="item-total">${currency(item.price * item.quantity)}</div>
      </div>
    `).join('');

    cartItems.querySelectorAll('.qty-btn').forEach(button => {
      button.addEventListener('click', () => {
        const delta = button.dataset.action === 'increase' ? 1 : -1;
        adjustQuantity(Number(button.dataset.productId), delta);
      });
    });

    cartItems.querySelectorAll('.remove-cart-item').forEach(button => {
      button.addEventListener('click', () => removeFromCart(Number(button.dataset.productId)));
    });
  }

  const { subtotal, discount, total } = getCartSummary();
  cartCount.textContent = `${state.cart.reduce((sum, item) => sum + item.quantity, 0)} Artículos`;
  subtotalValue.textContent = currency(subtotal);
  discountValue.textContent = discount > 0 ? `-${currency(discount)}` : currency(0);
  totalValue.textContent = currency(total);

  const received = Number(cashReceived.value) || 0;
  const change = received > 0 && paymentMethod.value === 'cash' ? Math.max(received - total, 0) : 0;
  changeValue.textContent = currency(change);
}

function removeFromCart(productId) {
  state.cart = state.cart.filter(item => item.id !== productId);
  renderCart();
  renderDashboard();
}

function updateInventoryTable() {
  if (!state.products.length) {
    inventoryTable.innerHTML = '<tr><td colspan="4">Aún no hay productos registrados.</td></tr>';
    return;
  }

  inventoryTable.innerHTML = state.products.map(product => {
    let badgeClass = 'safe';
    if (product.stock <= 8) badgeClass = 'danger';
    else if (product.stock <= 20) badgeClass = 'warn';

    return `
      <tr>
        <td>${escapeHtml(product.icon)} ${escapeHtml(product.name)}</td>
        <td>${escapeHtml(product.category)}</td>
        <td><span class="badge ${badgeClass}">${product.stock}</span></td>
        <td>${currency(product.price)}</td>
      </tr>
    `;
  }).join('');
}

function renderSalesTable() {
  if (!state.sales.length) {
    salesTable.innerHTML = '<tr><td colspan="5">Aún no hay ventas registradas.</td></tr>';
    return;
  }

  salesTable.innerHTML = state.sales.slice(0, 5).map(sale => `
    <tr>
      <td>${sale.id}</td>
      <td>${escapeHtml(sale.customer)}</td>
      <td>${escapeHtml(sale.seller || 'No registrado')}</td>
      <td>${sale.date}</td>
      <td>${currency(sale.total)}</td>
    </tr>
  `).join('');
}

function renderLatestProducts() {
  const products = [...state.products].slice(-5).reverse();
  if (!products.length) {
    latestProducts.innerHTML = '<li>Aún no hay productos registrados.</li>';
    return;
  }

  latestProducts.innerHTML = products.map(product => `
    <li>
      <span>${escapeHtml(product.name)}</span>
      <strong>${product.stock} und</strong>
    </li>
  `).join('');
}

function renderDashboard() {
  const total = currentRole === 'admin'
    ? state.sales.reduce((sum, sale) => sum + Number(sale.total || 0), 0)
    : state.summary.totalRevenue;

  cashBox.textContent = currency(total);
  totalRevenue.textContent = currency(total);
  totalSales.textContent = String(currentRole === 'admin' ? state.sales.length : state.summary.totalSales);
  totalProducts.textContent = String(state.products.length);
  totalClients.textContent = String(currentRole === 'admin'
    ? new Set(state.sales.map(sale => sale.customer)).size
    : state.summary.totalClients);
}

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, character => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;'
  })[character]);
}

function showInvoice(sale) {
  const date = new Date(sale.createdAt);
  const formattedDate = new Intl.DateTimeFormat('es', {
    dateStyle: 'medium',
    timeStyle: 'short'
  }).format(date);
  const paymentLabels = {
    cash: 'Efectivo',
    card: 'Tarjeta',
    transfer: 'Transferencia'
  };
  const itemCount = sale.items.reduce((count, item) => count + item.quantity, 0);

  invoiceContent.innerHTML = `
    <header class="invoice-header">
      <div class="invoice-brand">
        <div>
          <h2 id="invoiceDialogTitle">SuperMarket</h2>
          <p>Tu tienda de confianza</p>
        </div>
      </div>
      <div class="invoice-heading">
        <p>FACTURA</p>
        <strong class="invoice-number">${escapeHtml(sale.id)}</strong>
        <span class="invoice-status">PAGADA</span>
      </div>
    </header>
    <div class="invoice-meta">
      <p><span>Cliente:</span><strong>${escapeHtml(sale.customer)}</strong></p>
      <p><span>Vendedor:</span><strong>${escapeHtml(sale.seller || 'No registrado')}</strong></p>
      <p><span>Fecha de emisión:</span><strong>${escapeHtml(formattedDate)}</strong></p>
      <p><span>Forma de pago:</span><strong>${escapeHtml(paymentLabels[sale.payment] || sale.payment)}</strong></p>
    </div>
    <div class="invoice-table-wrap">
      <table class="invoice-table">
        <thead>
          <tr>
            <th>Producto</th>
            <th>Cantidad</th>
            <th>Precio unitario</th>
            <th>Importe</th>
          </tr>
        </thead>
        <tbody>
          ${sale.items.map(item => `
            <tr>
              <td>${escapeHtml(item.name)}</td>
              <td>${item.quantity}</td>
              <td>${currency(item.price)}</td>
              <td>${currency(item.price * item.quantity)}</td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>
    <div class="invoice-bottom">
      <div class="invoice-payment-note">
        <p>Documento generado por SuperMarket.</p>
      </div>
      <div class="invoice-totals">
        <p><span>Subtotal (${itemCount} artículos)</span><strong>${currency(sale.subtotal)}</strong></p>
        <p><span>Descuento (${sale.discountRate}%)</span><strong>${sale.discount > 0 ? '-' : ''}${currency(sale.discount)}</strong></p>
        <p class="invoice-grand-total"><span>Total pagado</span><strong>${currency(sale.total)}</strong></p>
        <p><span>Recibido</span><strong>${currency(sale.received)}</strong></p>
        <p><span>Cambio</span><strong>${currency(sale.change)}</strong></p>
      </div>
    </div>
    <footer class="invoice-thanks">
      <p>¡Gracias por tu compra!</p>
    </footer>
  `;

  invoiceDialog.showModal();
}

async function completeSale() {
  if (!state.cart.length) {
    alert('Debes agregar productos al carrito antes de finalizar la venta.');
    return;
  }

  const { total, selectedDiscountRate } = getCartSummary();
  const payment = paymentMethod.value;
  const customer = customerName.value.trim() || 'Cliente general';
  const received = Number(cashReceived.value) || 0;

  if (payment === 'cash' && received < total) {
    alert('El monto recibido debe ser igual o mayor al total.');
    return;
  }

  checkoutButton.disabled = true;
  try {
    const result = await apiRequest('sales', 'POST', {
      customer,
      payment,
      received,
      discountRate: selectedDiscountRate,
      items: state.cart.map(item => ({ id: item.id, quantity: item.quantity }))
    });
    applyServerState(result.state);
    state.cart = [];
    customerName.value = 'Cliente general';
    paymentMethod.value = 'cash';
    discountRate.value = '0';
    cashReceived.value = '';
    renderAll();
    showInvoice(result.sale);
  } catch (error) {
    alert(error.message);
  } finally {
    checkoutButton.disabled = false;
  }
}

function newSale() {
  state.cart = [];
  customerName.value = 'Cliente general';
  paymentMethod.value = 'cash';
  discountRate.value = '0';
  cashReceived.value = '';
  renderAll();
}

function renderAll() {
  renderFilters();
  renderProducts();
  renderCart();
  renderDashboard();
  updateInventoryTable();
  renderSalesTable();
  renderLatestProducts();
}

function renderActiveView() {
  const requestedView = window.location.hash.slice(1);
  let activeView = [...pageViews].find(view => view.id === requestedView) || document.querySelector('#dashboard');
  if (currentRole !== 'admin' && activeView.id !== 'venta' && activeView.id !== 'dashboard') {
    activeView = document.querySelector('#venta');
    if (window.location.hash !== '#venta') window.location.hash = '#venta';
  }

  pageViews.forEach(view => {
    const isActive = view === activeView;
    view.classList.toggle('active', isActive);
    view.hidden = !isActive;
  });

  navigationLinks.forEach(link => {
    const isActive = link.hash === `#${activeView.id}`;
    link.classList.toggle('active', isActive);
    if (isActive) {
      link.setAttribute('aria-current', 'page');
    } else {
      link.removeAttribute('aria-current');
    }
  });
}

async function handleProductSubmit(event) {
  event.preventDefault();

  const name = productName.value.trim();
  const category = productCategory.value.trim();
  const price = Number(productPrice.value);
  const stock = Number(productStock.value);

  if (!name || !category || !Number.isFinite(price) || price <= 0 || !Number.isFinite(stock) || stock < 0) {
    alert('Completa correctamente todos los campos del producto.');
    return;
  }

  const submitButton = productForm.querySelector('button[type="submit"]');
  submitButton.disabled = true;
  try {
    const result = await apiRequest('products', 'POST', { name, category, price, stock });
    applyServerState(result.state);
    productForm.reset();
    renderAll();
    alert('Producto registrado correctamente.');
  } catch (error) {
    alert(error.message);
  } finally {
    submitButton.disabled = false;
  }
}

searchInput.addEventListener('input', renderProducts);
checkoutButton.addEventListener('click', completeSale);
newSaleButton.addEventListener('click', newSale);
cashReceived.addEventListener('input', renderCart);
paymentMethod.addEventListener('change', renderCart);
discountRate.addEventListener('change', renderCart);
productForm.addEventListener('submit', handleProductSubmit);
window.addEventListener('hashchange', renderActiveView);
closeInvoiceButton.addEventListener('click', () => invoiceDialog.close());
printInvoiceButton.addEventListener('click', () => window.print());

if (!categoryFilters.dataset.selected) {
  categoryFilters.dataset.selected = 'Todos';
}

async function initializeApp() {
  try {
    const auth = await apiRequest('auth');
    csrfToken = auth.csrfToken;
    if (auth.authenticated) {
      await enterApplication(auth.username, auth.role);
    } else {
      renderAuthScreen(auth.needsSetup);
    }
  } catch (error) {
    console.error(error);
    renderAuthScreen(false, `No se pudo conectar con el servidor: ${error.message}`);
  }
}

logoutButton.addEventListener('click', handleLogout);
initializeApp();
