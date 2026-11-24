import { Application } from '@hotwired/stimulus';
import EmployeePickerController from './controllers/employee_picker_controller.js';
import EbpSyncController from './controllers/ebp_sync_controller.js';
import InventoryCountController from './controllers/inventory_count_controller.js';
import InventoryDeadlineController from './controllers/inventory_deadline_controller.js';
import InventoryLiveController from './controllers/inventory_live_controller.js';
import InventoryWizardController from './controllers/inventory_wizard_controller.js';
import OpeningBalanceController from './controllers/opening_balance_controller.js';
import WorkspaceController from './controllers/workspace_controller.js';

const app = Application.start();
app.register('workspace', WorkspaceController);
app.register('employee-picker', EmployeePickerController);
app.register('ebp-sync', EbpSyncController);
app.register('inventory-count', InventoryCountController);
app.register('inventory-deadline', InventoryDeadlineController);
app.register('inventory-live', InventoryLiveController);
app.register('inventory-wizard', InventoryWizardController);
app.register('opening-balance', OpeningBalanceController);

if ('serviceWorker' in navigator && document.body.classList.contains('employee-layout')) {
    navigator.serviceWorker.register('/sw.js').catch(() => {});
}
