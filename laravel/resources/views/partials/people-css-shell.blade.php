:root {
  --venusep-black: #1f1e1e;
  --venusep-cream: #ffffff;
  --venusep-border: #ddd7ce;
  --venusep-text: #050505;
  --venusep-sidebar-width: 235px;
  --venusep-header-height: 58px;
}

* {
  box-sizing: border-box;
}

html,
body {
  margin: 0;
  min-height: 100%;
}

body {
  color: var(--venusep-text);
  background: var(--venusep-cream);
  font-family: "Inter", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  font-size: 0.88rem;
  overflow-x: hidden;
}

.app-wrapper {
  --lte-sidebar-width: var(--venusep-sidebar-width);
  display: block;
  width: 100%;
  min-height: 100vh;
  background: var(--venusep-cream);
}

.app-header {
  position: fixed;
  top: 0;
  right: 0;
  left: var(--venusep-sidebar-width);
  z-index: 1030;
  min-height: var(--venusep-header-height);
  height: var(--venusep-header-height);
  background: var(--venusep-black);
  border-bottom: 1px solid #111;
  color: #fff;
  display: flex;
  align-items: center;
}
