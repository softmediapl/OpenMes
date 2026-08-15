import { createContext, useContext, useMemo, useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Dropdown, IconButton } from '@openmes/ui';
import AppLayout from '../../../layouts/AppLayout';
import { __, formatDateTime, formatNumber } from '../../../lib/i18n';
import { assertQuantityPrecision, quantityInputConfig } from '../../../lib/configuredQuantity';
import {
    OPEN_REPLENISHMENT_STATUSES,
    asNumber,
    availableQuantity,
    remainingQuantity,
    stockLevel,
    warehouseStockOptions,
} from './helpers';

const TABS = [
    ['stock', 'Workstation stock'],
    ['replenishments', 'Replenishments'],
    ['policies', 'Material policies'],
];

const STATUS_LABELS = {
    requested: 'Requested',
    assigned: 'Assigned',
    partially_delivered: 'Partially delivered',
    delivered: 'Delivered',
    cancelled: 'Cancelled',
};

const STATUS_CLASSES = {
    requested: 'bg-om-pending-bg text-om-pending',
    assigned: 'bg-om-chip text-om-accent',
    partially_delivered: 'bg-om-running-bg text-om-running',
    delivered: 'bg-om-running-bg text-om-running',
    cancelled: 'bg-om-blocked-bg text-om-blocked',
};

const inputClass = 'w-full rounded-md border border-om-line bg-om-card px-3 py-2 text-sm text-om-ink focus:outline-none focus:ring-2 focus:ring-om-accent disabled:opacity-60';
const secondaryButton = 'rounded-md border border-om-line bg-om-card px-3 py-2 text-sm font-medium text-om-ink hover:bg-om-bg disabled:opacity-50';
const primaryButton = 'rounded-md bg-om-accent px-3 py-2 text-sm font-semibold text-white hover:brightness-95 disabled:opacity-50';
const dangerButton = 'rounded-md bg-om-blocked-bg px-3 py-2 text-sm font-semibold text-om-blocked hover:brightness-95 disabled:opacity-50';

function Field({ label, error, hint, children }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-semibold uppercase text-om-muted">{label}</span>
            {children}
            {hint && <span className="mt-1 block text-xs text-om-faint">{hint}</span>}
            {error && <span className="mt-1 block text-xs text-om-blocked">{error}</span>}
        </label>
    );
}

function Modal({ title, description, onClose, children }) {
    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="workstation-material-modal-title"
            onMouseDown={onClose}
        >
            <div
                className="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-om-sm bg-om-card shadow-xl"
                onMouseDown={(event) => event.stopPropagation()}
            >
                <div className="flex items-start justify-between border-b border-om-line p-5">
                    <div>
                        <h2 id="workstation-material-modal-title" className="text-lg font-bold text-om-ink">{title}</h2>
                        {description && <p className="mt-1 text-sm text-om-muted">{description}</p>}
                    </div>
                    <button type="button" onClick={onClose} className="text-2xl leading-none text-om-faint hover:text-om-ink" aria-label={__('Close')}>
                        &times;
                    </button>
                </div>
                <div className="p-5">{children}</div>
            </div>
        </div>
    );
}

function FormActions({ processing, onClose, submitLabel }) {
    return (
        <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={onClose} className={secondaryButton}>{__('Cancel')}</button>
            <button type="submit" disabled={processing} className={primaryButton}>{submitLabel}</button>
        </div>
    );
}

const QuantityPrecisionContext = createContext({});

function Quantity({ value, unit }) {
    const unitPrecisions = useContext(QuantityPrecisionContext);
    return <span className="font-mono tabular-nums">{formatNumber(asNumber(value), { maximumFractionDigits: assertQuantityPrecision(unitPrecisions[unit], unit) })} {unit || ''}</span>;
}

function useConfiguredQuantity(unit) {
    const unitPrecisions = useContext(QuantityPrecisionContext);
    const precision = unit ? assertQuantityPrecision(unitPrecisions[unit], unit) : null;

    return {
        format: (value) => formatNumber(asNumber(value), {
            maximumFractionDigits: assertQuantityPrecision(unitPrecisions[unit], unit),
        }),
        input: precision === null ? null : quantityInputConfig(precision, unit),
    };
}

function StatusBadge({ status }) {
    return (
        <span className={`inline-flex rounded px-2 py-1 text-xs font-semibold ${STATUS_CLASSES[status] ?? 'bg-om-chip text-om-muted'}`}>
            {__(STATUS_LABELS[status] ?? status)}
        </span>
    );
}

function EmptyRow({ columns, text }) {
    return (
        <tr>
            <td colSpan={columns} className="px-4 py-12 text-center text-sm text-om-faint">{text}</td>
        </tr>
    );
}

function RowAction({ label, path, onClick, variant = 'default', disabled = false }) {
    return (
        <IconButton
            variant={variant}
            onClick={onClick}
            disabled={disabled}
            title={__(label)}
            aria-label={__(label)}
            className={disabled ? 'cursor-not-allowed opacity-40' : ''}
        >
            <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d={path} />
            </svg>
        </IconButton>
    );
}

const ACTION_ICONS = {
    assign: 'M18 9v6m3-3h-6m-2 7a6 6 0 00-12 0v1h12v-1zM7 11a4 4 0 100-8 4 4 0 000 8z',
    deliver: 'M9 17H5a2 2 0 01-2-2V5a2 2 0 012-2h10a2 2 0 012 2v10a2 2 0 01-2 2h-4m0 0l3-3m-3 3l3 3',
    cancel: 'M6 18L18 6M6 6l12 12',
    return: 'M3 10h11a4 4 0 010 8h-1m-10-8l4-4m-4 4l4 4',
    edit: 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
    delete: 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16',
};

function IssueModal({ workstations, materials, warehouses, warehouseStocks, onClose }) {
    const form = useForm({
        workstation_id: '', warehouse_id: '', material_id: '', material_lot_id: '', quantity: '', reason: '',
    });
    const material = materials.find((item) => Number(item.id) === Number(form.data.material_id));
    const configuredQuantity = useConfiguredQuantity(material?.unit_of_measure);
    const stockOptions = warehouseStockOptions(
        warehouseStocks,
        form.data.warehouse_id,
        form.data.material_id,
        material?.tracking_type,
    );
    const tracked = material?.tracking_type && material.tracking_type !== 'none';

    function submit(event) {
        event.preventDefault();
        form.transform((data) => ({ ...data, material_lot_id: data.material_lot_id || null }));
        form.post('/admin/workstation-materials/issue', { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Modal title={__('Issue material to workstation')} description={__('Move material from warehouse stock to the workstation without recording production consumption.')} onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <Field label={__('Workstation')} error={form.errors.workstation_id}>
                    <select required value={form.data.workstation_id} onChange={(event) => form.setData('workstation_id', event.target.value)} className={inputClass}>
                        <option value="">{__('Select workstation')}</option>
                        {workstations.map((item) => <option key={item.id} value={item.id}>{item.code} - {item.name}</option>)}
                    </select>
                </Field>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={__('Source warehouse')} error={form.errors.warehouse_id}>
                        <select required value={form.data.warehouse_id} onChange={(event) => form.setData({ ...form.data, warehouse_id: event.target.value, material_lot_id: '' })} className={inputClass}>
                            <option value="">{__('Select warehouse')}</option>
                            {warehouses.map((item) => <option key={item.id} value={item.id}>{item.code} - {item.name}</option>)}
                        </select>
                    </Field>
                    <Field label={__('Material')} error={form.errors.material_id}>
                        <select required value={form.data.material_id} onChange={(event) => form.setData({ ...form.data, material_id: event.target.value, material_lot_id: '' })} className={inputClass}>
                            <option value="">{__('Select material')}</option>
                            {materials.map((item) => <option key={item.id} value={item.id}>{item.code} - {item.name}</option>)}
                        </select>
                    </Field>
                </div>
                {tracked && (
                    <Field label={__('Material lot')} error={form.errors.material_lot_id}>
                        <select required value={form.data.material_lot_id} onChange={(event) => form.setData('material_lot_id', event.target.value)} className={inputClass}>
                            <option value="">{__('Select available lot')}</option>
                            {stockOptions.map((item) => (
                                <option key={item.id} value={item.material_lot_id}>
                                    {item.material_lot?.lot_number} - {configuredQuantity.format(item.quantity)} {item.unit_of_measure}
                                </option>
                            ))}
                        </select>
                    </Field>
                )}
                {form.data.warehouse_id && form.data.material_id && stockOptions.length === 0 && (
                    <p className="rounded-md bg-om-blocked-bg px-3 py-2 text-sm text-om-blocked">{__('No available stock in the selected warehouse.')}</p>
                )}
                <Field label={__('Quantity')} error={form.errors.quantity} hint={material?.unit_of_measure}>
                    <input required disabled={!configuredQuantity.input} min="0" step={configuredQuantity.input?.step} type="number" value={form.data.quantity} onChange={(event) => form.setData('quantity', event.target.value)} className={inputClass} />
                </Field>
                <Field label={__('Reason')} error={form.errors.reason}>
                    <textarea rows="2" value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} className={inputClass} />
                </Field>
                <FormActions processing={form.processing} onClose={onClose} submitLabel={__('Issue material')} />
            </form>
        </Modal>
    );
}

function ReturnModal({ stock, warehouses, onClose }) {
    const form = useForm({ warehouse_id: '', quantity: '', reason: '' });
    const available = availableQuantity(stock);

    function submit(event) {
        event.preventDefault();
        form.post(`/admin/workstation-materials/${stock.id}/return`, { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Modal title={__('Return material to warehouse')} description={`${stock.workstation?.name} - ${stock.material?.name}`} onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <p className="rounded-md bg-om-panel px-3 py-2 text-sm text-om-muted">
                    {__('Available to return')}: <Quantity value={available} unit={stock.unit_of_measure} />
                </p>
                <Field label={__('Destination warehouse')} error={form.errors.warehouse_id}>
                    <select required value={form.data.warehouse_id} onChange={(event) => form.setData('warehouse_id', event.target.value)} className={inputClass}>
                        <option value="">{__('Select warehouse')}</option>
                        {warehouses.map((item) => <option key={item.id} value={item.id}>{item.code} - {item.name}</option>)}
                    </select>
                </Field>
                <Field label={__('Quantity')} error={form.errors.quantity} hint={stock.unit_of_measure}>
                    <input required min="0" max={available} step="any" type="number" value={form.data.quantity} onChange={(event) => form.setData('quantity', event.target.value)} className={inputClass} />
                </Field>
                <Field label={__('Reason')} error={form.errors.reason}>
                    <textarea rows="2" value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} className={inputClass} />
                </Field>
                <FormActions processing={form.processing} onClose={onClose} submitLabel={__('Return material')} />
            </form>
        </Modal>
    );
}

function PolicyModal({ policy, workstations, materials, warehouses, users, onClose }) {
    const editing = Boolean(policy);
    const form = useForm({
        workstation_id: policy?.workstation_id ?? '',
        material_id: policy?.material_id ?? '',
        source_warehouse_id: policy?.source_warehouse_id ?? '',
        reorder_point: policy?.reorder_point ?? '',
        target_quantity: policy?.target_quantity ?? '',
        issue_increment: policy?.issue_increment ?? '',
        replenishment_mode: policy?.replenishment_mode ?? 'assigned',
        default_assignee_id: policy?.default_assignee_id ?? '',
        is_active: policy?.is_active ?? true,
    });
    const material = materials.find((item) => Number(item.id) === Number(form.data.material_id));

    function submit(event) {
        event.preventDefault();
        form.transform((data) => ({ ...data, default_assignee_id: data.default_assignee_id || null, issue_increment: data.issue_increment || null }));
        const options = { preserveScroll: true, onSuccess: onClose };
        if (editing) form.put(`/admin/workstation-material-policies/${policy.id}`, options);
        else form.post('/admin/workstation-material-policies', options);
    }

    return (
        <Modal title={editing ? __('Edit material policy') : __('New material policy')} description={__('Define when and how this workstation receives a local material refill.')} onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={__('Workstation')} error={form.errors.workstation_id}>
                        <select required value={form.data.workstation_id} onChange={(event) => form.setData('workstation_id', event.target.value)} className={inputClass}>
                            <option value="">{__('Select workstation')}</option>
                            {workstations.map((item) => <option key={item.id} value={item.id}>{item.code} - {item.name}</option>)}
                        </select>
                    </Field>
                    <Field label={__('Material')} error={form.errors.material_id}>
                        <select required value={form.data.material_id} onChange={(event) => form.setData('material_id', event.target.value)} className={inputClass}>
                            <option value="">{__('Select material')}</option>
                            {materials.map((item) => <option key={item.id} value={item.id}>{item.code} - {item.name}</option>)}
                        </select>
                    </Field>
                </div>
                <Field label={__('Source warehouse')} error={form.errors.source_warehouse_id}>
                    <select required value={form.data.source_warehouse_id} onChange={(event) => form.setData('source_warehouse_id', event.target.value)} className={inputClass}>
                        <option value="">{__('Select warehouse')}</option>
                        {warehouses.map((item) => <option key={item.id} value={item.id}>{item.code} - {item.name}</option>)}
                    </select>
                </Field>
                <div className="grid gap-4 sm:grid-cols-3">
                    <Field label={__('Reorder point')} error={form.errors.reorder_point} hint={material?.unit_of_measure}>
                        <input required min="0" step="any" type="number" value={form.data.reorder_point} onChange={(event) => form.setData('reorder_point', event.target.value)} className={inputClass} />
                    </Field>
                    <Field label={__('Target quantity')} error={form.errors.target_quantity} hint={material?.unit_of_measure}>
                        <input required min="0" step="any" type="number" value={form.data.target_quantity} onChange={(event) => form.setData('target_quantity', event.target.value)} className={inputClass} />
                    </Field>
                    <Field label={__('Issue increment')} error={form.errors.issue_increment} hint={__('Package or handling unit size')}>
                        <input min="0" step="any" type="number" value={form.data.issue_increment} onChange={(event) => form.setData('issue_increment', event.target.value)} className={inputClass} />
                    </Field>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={__('Replenishment mode')} error={form.errors.replenishment_mode}>
                        <select value={form.data.replenishment_mode} onChange={(event) => form.setData('replenishment_mode', event.target.value)} className={inputClass}>
                            <option value="assigned">{__('Assigned')}</option>
                            <option value="self_service">{__('Self-service')}</option>
                        </select>
                    </Field>
                    <Field label={__('Default assignee')} error={form.errors.default_assignee_id}>
                        <select disabled={form.data.replenishment_mode !== 'assigned'} value={form.data.default_assignee_id} onChange={(event) => form.setData('default_assignee_id', event.target.value)} className={inputClass}>
                            <option value="">{__('No default assignee')}</option>
                            {users.map((item) => <option key={item.id} value={item.id}>{item.name} ({item.email})</option>)}
                        </select>
                    </Field>
                </div>
                <label className="flex items-center gap-2 text-sm text-om-ink">
                    <input type="checkbox" checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} />
                    {__('Active policy')}
                </label>
                <FormActions processing={form.processing} onClose={onClose} submitLabel={editing ? __('Save changes') : __('Create policy')} />
            </form>
        </Modal>
    );
}

function RequestModal({ policies, onClose }) {
    const activePolicies = policies.filter((policy) => policy.is_active);
    const form = useForm({ workstation_material_policy_id: '', quantity: '', priority: 0, notes: '' });
    const policy = activePolicies.find((item) => Number(item.id) === Number(form.data.workstation_material_policy_id));

    function submit(event) {
        event.preventDefault();
        form.transform((data) => ({ ...data, quantity: data.quantity || null }));
        form.post('/admin/material-replenishments', { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Modal title={__('Request replenishment')} description={__('Create a material refill task for a configured workstation policy.')} onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <Field label={__('Material policy')} error={form.errors.workstation_material_policy_id}>
                    <select required value={form.data.workstation_material_policy_id} onChange={(event) => form.setData('workstation_material_policy_id', event.target.value)} className={inputClass}>
                        <option value="">{__('Select policy')}</option>
                        {activePolicies.map((item) => <option key={item.id} value={item.id}>{item.workstation?.code} - {item.material?.code}</option>)}
                    </select>
                </Field>
                {policy && (
                    <p className="rounded-md bg-om-panel px-3 py-2 text-sm text-om-muted">
                        {__('Source')}: {policy.source_warehouse?.code} · {__('Target')}: <Quantity value={policy.target_quantity} unit={policy.material?.unit_of_measure} />
                    </p>
                )}
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={__('Quantity')} error={form.errors.quantity} hint={__('Leave empty to refill to the configured target.') }>
                        <input min="0" step="any" type="number" value={form.data.quantity} onChange={(event) => form.setData('quantity', event.target.value)} className={inputClass} />
                    </Field>
                    <Field label={__('Priority')} error={form.errors.priority}>
                        <input min="0" max="255" type="number" value={form.data.priority} onChange={(event) => form.setData('priority', event.target.value)} className={inputClass} />
                    </Field>
                </div>
                <Field label={__('Notes')} error={form.errors.notes}>
                    <textarea rows="2" value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} className={inputClass} />
                </Field>
                <FormActions processing={form.processing} onClose={onClose} submitLabel={__('Create request')} />
            </form>
        </Modal>
    );
}

function AssignModal({ request, users, onClose }) {
    const form = useForm({ assignee_id: request.assigned_to_id ?? '' });
    function submit(event) {
        event.preventDefault();
        form.post(`/admin/material-replenishments/${request.id}/assign`, { preserveScroll: true, onSuccess: onClose });
    }
    return (
        <Modal title={__('Assign replenishment')} description={`${request.workstation?.name} - ${request.material?.name}`} onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <Field label={__('Assignee')} error={form.errors.assignee_id}>
                    <select required value={form.data.assignee_id} onChange={(event) => form.setData('assignee_id', event.target.value)} className={inputClass}>
                        <option value="">{__('Select user')}</option>
                        {users.map((item) => <option key={item.id} value={item.id}>{item.name} ({item.email})</option>)}
                    </select>
                </Field>
                <FormActions processing={form.processing} onClose={onClose} submitLabel={__('Assign')} />
            </form>
        </Modal>
    );
}

function DeliverModal({ request, materials, warehouseStocks, onClose }) {
    const form = useForm({ material_lot_id: '', quantity: remainingQuantity(request), notes: '' });
    const material = materials.find((item) => Number(item.id) === Number(request.material_id)) ?? request.material;
    const stockOptions = warehouseStockOptions(warehouseStocks, request.source_warehouse_id, request.material_id, material?.tracking_type);
    const tracked = material?.tracking_type && material.tracking_type !== 'none';
    const configuredQuantity = useConfiguredQuantity(request.unit_of_measure);

    function submit(event) {
        event.preventDefault();
        form.transform((data) => ({ ...data, material_lot_id: data.material_lot_id || null }));
        form.post(`/admin/material-replenishments/${request.id}/deliver`, { preserveScroll: true, onSuccess: onClose });
    }
    return (
        <Modal title={__('Deliver replenishment')} description={`${request.source_warehouse?.code} → ${request.workstation?.name}`} onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                {tracked && (
                    <Field label={__('Material lot')} error={form.errors.material_lot_id}>
                        <select required value={form.data.material_lot_id} onChange={(event) => form.setData('material_lot_id', event.target.value)} className={inputClass}>
                            <option value="">{__('Select available lot')}</option>
                            {stockOptions.map((item) => <option key={item.id} value={item.material_lot_id}>{item.material_lot?.lot_number} - {configuredQuantity.format(item.quantity)} {item.unit_of_measure}</option>)}
                        </select>
                    </Field>
                )}
                {stockOptions.length === 0 && (
                    <p className="rounded-md bg-om-blocked-bg px-3 py-2 text-sm text-om-blocked">{__('No available stock in the source warehouse.')}</p>
                )}
                <Field label={__('Quantity')} error={form.errors.quantity} hint={`${__('Remaining')}: ${configuredQuantity.format(remainingQuantity(request))} ${request.unit_of_measure}`}>
                    <input required min="0" step={configuredQuantity.input.step} type="number" value={form.data.quantity} onChange={(event) => form.setData('quantity', event.target.value)} className={inputClass} />
                </Field>
                <Field label={__('Delivery notes')} error={form.errors.notes}>
                    <textarea rows="2" value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} className={inputClass} />
                </Field>
                <FormActions processing={form.processing} onClose={onClose} submitLabel={__('Record delivery')} />
            </form>
        </Modal>
    );
}

function CancelModal({ request, onClose }) {
    const form = useForm({ reason: '' });
    function submit(event) {
        event.preventDefault();
        form.post(`/admin/material-replenishments/${request.id}/cancel`, { preserveScroll: true, onSuccess: onClose });
    }
    return (
        <Modal title={__('Cancel replenishment')} description={`${request.workstation?.name} - ${request.material?.name}`} onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <Field label={__('Reason')} error={form.errors.reason}>
                    <textarea rows="3" value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} className={inputClass} />
                </Field>
                <div className="flex justify-end gap-2 pt-2">
                    <button type="button" onClick={onClose} className={secondaryButton}>{__('Keep request')}</button>
                    <button type="submit" disabled={form.processing} className={dangerButton}>{__('Cancel request')}</button>
                </div>
            </form>
        </Modal>
    );
}

export default function WorkstationMaterialsIndex() {
    const {
        stocks = [], policies = [], replenishmentRequests = [], workstations = [], materials = [],
        warehouses = [], warehouseStocks = [], users = [], unitPrecisions = {},
    } = usePage().props;
    const [tab, setTab] = useState('stock');
    const [search, setSearch] = useState('');
    const [workstationFilter, setWorkstationFilter] = useState('');
    const [modal, setModal] = useState(null);

    const policyByPair = useMemo(() => Object.fromEntries(policies.map((policy) => [`${policy.workstation_id}:${policy.material_id}`, policy])), [policies]);
    const aggregateByPair = useMemo(() => {
        const totals = {};
        stocks.forEach((stock) => {
            const key = `${stock.workstation_id}:${stock.material_id}`;
            totals[key] = (totals[key] ?? 0) + availableQuantity(stock);
        });
        return totals;
    }, [stocks]);
    const query = search.trim().toLowerCase();
    const contains = (...values) => !query || values.some((value) => String(value ?? '').toLowerCase().includes(query));
    const matchesWorkstation = (workstation) => !workstationFilter
        || String(workstation?.id) === String(workstationFilter);
    const visibleStocks = stocks.filter((stock) => matchesWorkstation(stock.workstation)
        && contains(stock.workstation?.code, stock.workstation?.name, stock.material?.code, stock.material?.name, stock.material_lot?.lot_number));
    const visibleRequests = replenishmentRequests.filter((request) => matchesWorkstation(request.workstation)
        && contains(request.workstation?.code, request.workstation?.name, request.material?.code, request.material?.name, request.status, request.assigned_to?.name));
    const visiblePolicies = policies.filter((policy) => matchesWorkstation(policy.workstation)
        && contains(policy.workstation?.code, policy.workstation?.name, policy.material?.code, policy.material?.name, policy.source_warehouse?.code));
    const closeModal = () => setModal(null);

    function deletePolicy(policy) {
        if (window.confirm(__('Delete material policy for :workstation / :material?', { workstation: policy.workstation?.code, material: policy.material?.code }))) {
            router.delete(`/admin/workstation-material-policies/${policy.id}`, { preserveScroll: true });
        }
    }

    return (
        <QuantityPrecisionContext.Provider value={unitPrecisions}>
        <>
            <Head title={__('Workstation Materials')} />
            <div className="mx-auto max-w-[1500px] px-6 py-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold text-om-ink">{__('Workstation Materials')}</h1>
                        <p className="mt-1 text-sm text-om-muted">{__('Manage local material stock, replenishment work and refill policies.')}</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" onClick={() => setModal({ type: 'issue' })} className={secondaryButton}>{__('Issue material')}</button>
                        <button type="button" onClick={() => setModal({ type: 'request' })} className={primaryButton}>{__('Request replenishment')}</button>
                    </div>
                </div>

                <div className="mt-6 flex flex-wrap items-center justify-between gap-3 border-b border-om-line">
                    <div className="flex gap-1" role="tablist">
                        {TABS.map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                role="tab"
                                aria-selected={tab === key}
                                onClick={() => setTab(key)}
                                className={`border-b-2 px-4 py-3 text-sm font-semibold ${tab === key ? 'border-om-accent text-om-ink' : 'border-transparent text-om-muted hover:text-om-ink'}`}
                            >
                                {__(label)}
                            </button>
                        ))}
                    </div>
                    <div className="mb-2 flex flex-wrap items-center justify-end gap-2">
                        <Dropdown
                            searchable
                            value={workstationFilter}
                            onChange={setWorkstationFilter}
                            options={[
                                { value: '', label: __('All workstations') },
                                ...workstations.map((workstation) => ({
                                    value: String(workstation.id),
                                    label: `${workstation.code} — ${workstation.name}`,
                                })),
                            ]}
                            placeholder={__('All workstations')}
                            aria-label={__('Workstation')}
                            className="w-72 max-w-full"
                        />
                        <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder={__('Search...')} aria-label={__('Search...')} className="w-72 max-w-full rounded-md border border-om-line bg-om-card px-3 py-2 text-sm" />
                    </div>
                </div>

                {tab === 'stock' && (
                    <div className="mt-4 overflow-x-auto rounded-om-sm border border-om-line bg-om-card">
                        <table className="w-full min-w-[900px] text-left text-[13.5px]">
                            <thead className="bg-om-panel font-mono text-[9px] uppercase tracking-[0.1em] text-om-muted"><tr>
                                <th className="px-4 py-3">{__('Workstation')}</th><th className="px-4 py-3">{__('Material')}</th><th className="px-4 py-3">{__('Lot')}</th>
                                <th className="px-4 py-3 text-right">{__('On hand')}</th><th className="px-4 py-3 text-right">{__('Reserved')}</th><th className="px-4 py-3 text-right">{__('Available')}</th>
                                <th className="px-4 py-3">{__('Level')}</th><th className="px-4 py-3 text-right">{__('Actions')}</th>
                            </tr></thead>
                            <tbody className="divide-y divide-om-line">
                                {visibleStocks.length === 0 && <EmptyRow columns={8} text={__('No workstation stock yet.')} />}
                                {visibleStocks.map((stock) => {
                                    const key = `${stock.workstation_id}:${stock.material_id}`;
                                    const policy = policyByPair[key];
                                    const aggregateStock = { quantity: aggregateByPair[key], reserved_quantity: 0 };
                                    const level = stockLevel(aggregateStock, policy);
                                    return <tr key={stock.id} className="hover:bg-om-bg">
                                        <td className="px-4 py-3"><div className="font-medium text-om-ink">{stock.workstation?.name}</div><div className="font-mono text-xs text-om-faint">{stock.workstation?.code}</div></td>
                                        <td className="px-4 py-3"><div className="font-medium text-om-ink">{stock.material?.name}</div><div className="font-mono text-xs text-om-faint">{stock.material?.code}</div></td>
                                        <td className="px-4 py-3 font-mono text-xs">{stock.material_lot?.lot_number ?? '—'}</td>
                                        <td className="px-4 py-3 text-right"><Quantity value={stock.quantity} unit={stock.unit_of_measure} /></td>
                                        <td className="px-4 py-3 text-right"><Quantity value={stock.reserved_quantity} unit={stock.unit_of_measure} /></td>
                                        <td className="px-4 py-3 text-right font-semibold"><Quantity value={availableQuantity(stock)} unit={stock.unit_of_measure} /></td>
                                        <td className="px-4 py-3">
                                            {level === 'low' && <span className="rounded bg-om-blocked-bg px-2 py-1 text-xs font-semibold text-om-blocked">{__('Below reorder point')}</span>}
                                            {level === 'ok' && <span className="rounded bg-om-running-bg px-2 py-1 text-xs font-semibold text-om-running">{__('Stocked')}</span>}
                                            {level === 'inactive' && <span className="rounded bg-om-chip px-2 py-1 text-xs text-om-muted">{__('Policy inactive')}</span>}
                                            {level === 'unmanaged' && <span className="rounded bg-om-chip px-2 py-1 text-xs text-om-muted">{__('No policy')}</span>}
                                        </td>
                                        <td className="px-4 py-3"><div className="flex justify-end">
                                            <RowAction label="Return" path={ACTION_ICONS.return} disabled={availableQuantity(stock) <= 0} onClick={() => setModal({ type: 'return', record: stock })} />
                                        </div></td>
                                    </tr>;
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                {tab === 'replenishments' && (
                    <div className="mt-4 overflow-x-auto rounded-om-sm border border-om-line bg-om-card">
                        <table className="w-full min-w-[1100px] text-left text-[13.5px]">
                            <thead className="bg-om-panel font-mono text-[9px] uppercase tracking-[0.1em] text-om-muted"><tr>
                                <th className="px-4 py-3">{__('Status')}</th><th className="px-4 py-3">{__('Workstation')}</th><th className="px-4 py-3">{__('Material')}</th><th className="px-4 py-3">{__('Source')}</th>
                                <th className="px-4 py-3 text-right">{__('Requested')}</th><th className="px-4 py-3 text-right">{__('Delivered')}</th><th className="px-4 py-3 text-right">{__('Remaining')}</th>
                                <th className="px-4 py-3">{__('Assignee')}</th><th className="px-4 py-3">{__('Requested at')}</th><th className="px-4 py-3 text-right">{__('Actions')}</th>
                            </tr></thead>
                            <tbody className="divide-y divide-om-line">
                                {visibleRequests.length === 0 && <EmptyRow columns={10} text={__('No replenishment requests yet.')} />}
                                {visibleRequests.map((request) => {
                                    const open = OPEN_REPLENISHMENT_STATUSES.has(request.status);
                                    return <tr key={request.id} className="hover:bg-om-bg">
                                        <td className="px-4 py-3"><StatusBadge status={request.status} /></td>
                                        <td className="px-4 py-3"><div className="font-medium">{request.workstation?.name}</div><div className="font-mono text-xs text-om-faint">{request.workstation?.code}</div></td>
                                        <td className="px-4 py-3"><div className="font-medium">{request.material?.name}</div><div className="font-mono text-xs text-om-faint">{request.material?.code}</div></td>
                                        <td className="px-4 py-3 font-mono text-xs">{request.source_warehouse?.code}</td>
                                        <td className="px-4 py-3 text-right"><Quantity value={request.requested_quantity} unit={request.unit_of_measure} /></td>
                                        <td className="px-4 py-3 text-right"><Quantity value={request.delivered_quantity} unit={request.unit_of_measure} /></td>
                                        <td className="px-4 py-3 text-right font-semibold"><Quantity value={remainingQuantity(request)} unit={request.unit_of_measure} /></td>
                                        <td className="px-4 py-3">{request.assigned_to?.name ?? '—'}</td>
                                        <td className="px-4 py-3 text-xs text-om-muted">{formatDateTime(request.requested_at)}</td>
                                        <td className="px-4 py-3"><div className="flex justify-end gap-2">
                                            {open && <RowAction label="Assign" path={ACTION_ICONS.assign} onClick={() => setModal({ type: 'assign', record: request })} />}
                                            {open && remainingQuantity(request) > 0 && <RowAction label="Deliver" path={ACTION_ICONS.deliver} variant="primary" onClick={() => setModal({ type: 'deliver', record: request })} />}
                                            {open && <RowAction label="Cancel" path={ACTION_ICONS.cancel} variant="danger" onClick={() => setModal({ type: 'cancel', record: request })} />}
                                        </div></td>
                                    </tr>;
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                {tab === 'policies' && (
                    <>
                        <div className="mt-4 flex justify-end"><button type="button" onClick={() => setModal({ type: 'policy' })} className={primaryButton}>{__('New material policy')}</button></div>
                        <div className="mt-3 overflow-x-auto rounded-om-sm border border-om-line bg-om-card">
                            <table className="w-full min-w-[1100px] text-left text-[13.5px]">
                                <thead className="bg-om-panel font-mono text-[9px] uppercase tracking-[0.1em] text-om-muted"><tr>
                                    <th className="px-4 py-3">{__('Workstation')}</th><th className="px-4 py-3">{__('Material')}</th><th className="px-4 py-3">{__('Source')}</th>
                                    <th className="px-4 py-3 text-right">{__('Reorder point')}</th><th className="px-4 py-3 text-right">{__('Target')}</th><th className="px-4 py-3 text-right">{__('Issue increment')}</th>
                                    <th className="px-4 py-3">{__('Mode')}</th><th className="px-4 py-3">{__('Assignee')}</th><th className="px-4 py-3">{__('Status')}</th><th className="px-4 py-3 text-right">{__('Actions')}</th>
                                </tr></thead>
                                <tbody className="divide-y divide-om-line">
                                    {visiblePolicies.length === 0 && <EmptyRow columns={10} text={__('No workstation material policies yet.')} />}
                                    {visiblePolicies.map((policy) => <tr key={policy.id} className="hover:bg-om-bg">
                                        <td className="px-4 py-3"><div className="font-medium">{policy.workstation?.name}</div><div className="font-mono text-xs text-om-faint">{policy.workstation?.code}</div></td>
                                        <td className="px-4 py-3"><div className="font-medium">{policy.material?.name}</div><div className="font-mono text-xs text-om-faint">{policy.material?.code}</div></td>
                                        <td className="px-4 py-3 font-mono text-xs">{policy.source_warehouse?.code}</td>
                                        <td className="px-4 py-3 text-right"><Quantity value={policy.reorder_point} unit={policy.material?.unit_of_measure} /></td>
                                        <td className="px-4 py-3 text-right"><Quantity value={policy.target_quantity} unit={policy.material?.unit_of_measure} /></td>
                                        <td className="px-4 py-3 text-right">{policy.issue_increment ? <Quantity value={policy.issue_increment} unit={policy.material?.unit_of_measure} /> : '—'}</td>
                                        <td className="px-4 py-3">{policy.replenishment_mode === 'assigned' ? __('Assigned') : __('Self-service')}</td>
                                        <td className="px-4 py-3">{policy.default_assignee?.name ?? '—'}</td>
                                        <td className="px-4 py-3">{policy.is_active ? __('Active') : __('Inactive')}</td>
                                        <td className="px-4 py-3"><div className="flex justify-end gap-2">
                                            <RowAction label="Edit" path={ACTION_ICONS.edit} onClick={() => setModal({ type: 'policy', record: policy })} />
                                            <RowAction label="Delete" path={ACTION_ICONS.delete} variant="danger" onClick={() => deletePolicy(policy)} />
                                        </div></td>
                                    </tr>)}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}
            </div>

            {modal?.type === 'issue' && <IssueModal workstations={workstations} materials={materials} warehouses={warehouses} warehouseStocks={warehouseStocks} onClose={closeModal} />}
            {modal?.type === 'return' && <ReturnModal stock={modal.record} warehouses={warehouses} onClose={closeModal} />}
            {modal?.type === 'policy' && <PolicyModal policy={modal.record} workstations={workstations} materials={materials} warehouses={warehouses} users={users} onClose={closeModal} />}
            {modal?.type === 'request' && <RequestModal policies={policies} onClose={closeModal} />}
            {modal?.type === 'assign' && <AssignModal request={modal.record} users={users} onClose={closeModal} />}
            {modal?.type === 'deliver' && <DeliverModal request={modal.record} materials={materials} warehouseStocks={warehouseStocks} onClose={closeModal} />}
            {modal?.type === 'cancel' && <CancelModal request={modal.record} onClose={closeModal} />}
        </>
        </QuantityPrecisionContext.Provider>
    );
}

WorkstationMaterialsIndex.layout = (page) => <AppLayout>{page}</AppLayout>;
