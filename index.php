<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="es">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>SuperMarket POS</title>
    <link rel="stylesheet" href="styles.css?v=developer-button-2" />
  </head>
  <body>
    <section class="auth-screen" id="authScreen" aria-live="polite">
      <div class="auth-card" id="authCard">
        <p class="eyebrow">SuperMarket POS</p>
        <h1>Verificando acceso...</h1>
      </div>
    </section>

    <div class="app-shell" id="appShell" hidden>
      <aside class="sidebar">
        <div class="brand">
          <div class="brand-mark">S</div>
          <div>
            <h1>SuperMarket</h1>
            <p>Control de ventas</p>
          </div>
        </div>

        <div class="account-card">
          <span id="loggedInUser"></span>
          <button class="account-logout" type="button" id="logoutButton">Cerrar sesión</button>
        </div>

        <nav class="sidebar-nav" aria-label="Menú principal">
          <a class="nav-item active" href="#dashboard" aria-current="page">
            <span>🏠</span>
            Dashboard
          </a>
          <a class="nav-item" href="#venta">
            <span>🛒</span>
            Venta
          </a>
          <a class="nav-item" href="#inventario">
            <span>📦</span>
            Inventario
          </a>
          <a class="nav-item" href="#reportes">
            <span>📊</span>
            Reportes
          </a>
        </nav>

        <div class="sidebar-card">
          <p class="label">Caja</p>
          <h3 id="cashBox">$0.00</h3>
          <small>Ventas del día</small>
        </div>
      </aside>

      <main class="main-panel">
        <section class="page-view active" id="dashboard" aria-labelledby="dashboardTitle">
          <header class="topbar">
            <div>
              <p class="eyebrow">Panel principal</p>
              <h2 id="dashboardTitle">Gestión de ventas</h2>
            </div>
            <div class="toolbar">
              <button class="primary-btn" type="button" id="newSaleButton">Visitar sitio web</button>
              <a
                class="developer-btn"
                href="https://jose0426.github.io/Jose_Pag_web/#inicio"
                target="_blank"
                rel="noopener noreferrer"
              >Acerca del programador</a>
            </div>
          </header>

          <div class="stat-grid">
          <article class="stat-card accent">
            <span class="icon">💰</span>
            <div>
              <p>Ingresos</p>
              <h3 id="totalRevenue">$0.00</h3>
            </div>
          </article>
          <article class="stat-card blue">
            <span class="icon">🧾</span>
            <div>
              <p>Ventas</p>
              <h3 id="totalSales">0</h3>
            </div>
          </article>
          <article class="stat-card green">
            <span class="icon">📦</span>
            <div>
              <p>Productos</p>
              <h3 id="totalProducts">0</h3>
            </div>
          </article>
          <article class="stat-card orange">
            <span class="icon">🧍</span>
            <div>
              <p>Clientes</p>
              <h3 id="totalClients">0</h3>
            </div>
          </article>
          </div>
        </section>

        <section class="page-view" id="venta" aria-labelledby="saleTitle">
          <header class="topbar">
            <div>
              <p class="eyebrow">Punto de venta</p>
              <h2 id="saleTitle">Registrar venta</h2>
            </div>
          </header>
          <div class="workspace">
          <div class="catalog-panel">
            <div class="panel-header">
              <h3>Productos</h3>
              <div class="search-wrap">
                <input id="searchInput" type="search" placeholder="Buscar producto..." />
              </div>
            </div>

            <div class="filter-row" id="categoryFilters"></div>
            <div class="product-grid" id="productGrid"></div>
          </div>

          <aside class="cart-panel">
            <div class="panel-header">
              <h3>Carrito de compras</h3>
              <span class="pill" id="cartCount">0 Artículos</span>
            </div>

            <div class="cart-items" id="cartItems"></div>

            <section class="order-summary" aria-labelledby="orderSummaryTitle">
              <h4 id="orderSummaryTitle">Resumen del pedido</h4>
              <div class="totals">
              <div>
                <span>Subtotal</span>
                <strong id="subtotalValue">$0.00</strong>
              </div>
              <div class="discount-total">
                <label for="discountRate">Descuento</label>
                <select id="discountRate">
                  <option value="0">Sin descuento</option>
                  <option value="5">5%</option>
                  <option value="10">10%</option>
                  <option value="15">15%</option>
                  <option value="20">20%</option>
                </select>
                <strong id="discountValue">-$0.00</strong>
              </div>
              <div class="grand-total">
                <span>Total</span>
                <strong id="totalValue">$0.00</strong>
              </div>
              </div>

              <div class="checkout-form">
              <label>
                Cliente
                <input id="customerName" type="text" placeholder="Nombre del cliente" value="Cliente general" />
              </label>

              <label>
                Pago
                <select id="paymentMethod">
                  <option value="cash">Efectivo</option>
                  <option value="card">Tarjeta</option>
                  <option value="transfer">Transferencia</option>
                </select>
              </label>

              <label>
                Dinero recibido
                <input id="cashReceived" type="number" min="0" step="0.01" placeholder="0.00" />
              </label>

              <div class="change-row">
                <span>Cambio</span>
                <strong id="changeValue">$0.00</strong>
              </div>

              <button class="primary-btn checkout-btn" type="button" id="checkoutButton">Finalizar venta</button>
              </div>
            </section>
          </aside>
          </div>
        </section>

        <section class="page-view" id="inventario" aria-labelledby="inventoryTitle">
          <header class="topbar">
            <div>
              <p class="eyebrow">Control de productos</p>
              <h2 id="inventoryTitle">Inventario</h2>
            </div>
          </header>
          <div class="inventory-layout">
            <div class="panel-box">
              <div class="panel-header slim">
                <h3>Productos registrados</h3>
              </div>
              <table>
                <thead>
                  <tr>
                    <th>Producto</th>
                    <th>Categoría</th>
                    <th>Stock</th>
                    <th>Precio</th>
                  </tr>
                </thead>
                <tbody id="inventoryTable"></tbody>
              </table>
            </div>

            <section class="inventory-section">
              <div class="panel-header">
                <h3>Registrar producto</h3>
              </div>

              <div class="inventory-manager">
                <form id="productForm" class="product-form">
                  <div class="form-grid">
                    <label>
                      Nombre del producto
                      <input id="productName" type="text" placeholder="Ej. Yogurt griego" required />
                    </label>

                    <label>
                      Categoría
                      <input id="productCategory" type="text" placeholder="Ej. Lácteos" required />
                    </label>

                    <label>
                      Precio
                      <input id="productPrice" type="number" min="0" step="0.01" placeholder="0.00" required />
                    </label>

                    <label>
                      Stock inicial
                      <input id="productStock" type="number" min="0" step="1" placeholder="0" required />
                    </label>
                  </div>

                  <button type="submit" class="primary-btn">Guardar producto</button>
                </form>

                <div class="inventory-preview">
                  <h4>Productos recientes</h4>
                  <ul id="latestProducts"></ul>
                </div>
              </div>
            </section>
          </div>
        </section>

        <section class="page-view" id="reportes" aria-labelledby="reportsTitle">
          <header class="topbar">
            <div>
              <p class="eyebrow">Resumen del negocio</p>
              <h2 id="reportsTitle">Reportes</h2>
            </div>
          </header>
          <div class="panel-box">
            <div class="panel-header slim">
              <h3>Ventas recientes</h3>
            </div>
            <table>
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Cliente</th>
                  <th>Vendedor</th>
                  <th>Fecha</th>
                  <th>Total</th>
                </tr>
              </thead>
              <tbody id="salesTable"></tbody>
            </table>
          </div>
        </section>
      </main>
    </div>

    <dialog class="invoice-dialog" id="invoiceDialog" aria-labelledby="invoiceDialogTitle">
      <div class="invoice-content" id="invoiceContent"></div>
      <div class="invoice-actions">
        <button class="ghost-btn" type="button" id="closeInvoiceButton">Cerrar</button>
        <button class="primary-btn" type="button" id="printInvoiceButton">Imprimir factura</button>
      </div>
    </dialog>

    <script src="script.js?v=discount-selection-1"></script>
  </body>
</html>
