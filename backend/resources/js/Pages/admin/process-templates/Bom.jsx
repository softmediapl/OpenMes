import { useState, useMemo } from 'react';
import { __ } from '../../../lib/i18n';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Dropdown } from '@openmes/ui';
import { DataTable } from '@openmes/ui/table';
import AppLayout from '../../../layouts/AppLayout';
import {
    formatQuantityRule,
    formatRoundingRule,
    quantityRuleMode,
} from './bomQuantityRule';

const TYPE_COLORS = {
    raw_material:  'bg-om-downtime-bg text-om-downtime',
    semi_finished: 'bg-om-chip text-om-accent',
    packaging:     'bg-om-chip text-om-ink',
};

function typeColorClass(code) {
    return TYPE_COLORS[code] ?? 'bg-om-chip text-om-ink';
}

function MaterialForm({ productType, processTemplate, materials, steps, item, onCancel }) {
    const isEdit = !!item;
    const usesExactRatio = quantityRuleMode(item) === 'ratio';
    const form = useForm({
        material_id: item ? String(item.material_id ?? '') : '',
        quantity_rule: usesExactRatio ? 'ratio' : 'per_unit',
        quantity_per_unit: item && !usesExactRatio ? String(item.quantity_per_unit ?? '') : '',
        component_quantity: usesExactRatio ? String(item.component_quantity) : '',
        output_quantity: usesExactRatio ? String(item.output_quantity) : '',
        template_step_id: item && item.template_step_id != null ? String(item.template_step_id) : '',
        scrap_percentage: item ? String(item.scrap_percentage ?? '0') : '0',
        rounding_mode: item ? (item.rounding_mode ?? 'none') : 'none',
        rounding_multiple: item ? String(item.rounding_multiple ?? '1') : '1',
        consumed_at: item ? (item.consumed_at ?? 'start') : 'start',
        notes: item ? (item.notes ?? '') : '',
    });

    const { data, setData, errors, processing } = form;

    const selectedMaterial = isEdit
        ? null
        : materials.find((m) => String(m.id) === String(data.material_id));
    const unit = isEdit ? item.unit_of_measure : selectedMaterial?.unit_of_measure;

    // When a material with a default scrap % is picked, pre-fill it (only while
    // the field still holds the untouched default) and surface that it was auto-set.
    const onMaterialChange = (id) => {
        setData('material_id', id);
        const m = materials.find((x) => String(x.id) === String(id));
        if (m && m.default_scrap_percentage != null && (data.scrap_percentage === '' || data.scrap_percentage === '0')) {
            setData('scrap_percentage', String(m.default_scrap_percentage));
        }
    };

    const setQuantityRule = (rule) => {
        setData({
            ...data,
            quantity_rule: rule,
            quantity_per_unit: rule === 'per_unit' ? data.quantity_per_unit : '',
            component_quantity: rule === 'ratio' ? data.component_quantity : '',
            output_quantity: rule === 'ratio' ? data.output_quantity : '',
        });
    };

    const submit = (e) => {
        e.preventDefault();
        const base = `/admin/product-types/${productType.id}/process-templates/${processTemplate.id}/bom`;
        if (isEdit) {
            form.put(`${base}/${item.id}`, { onSuccess: onCancel });
        } else {
            form.post(base, { onSuccess: onCancel });
        }
    };

    return (
        <div className="card mb-6" style={{ borderLeft: '4px solid #3b82f6' }}>
            <h3 className="text-lg font-semibold mb-4">
                {isEdit ? `${__("Edit BOM Item")} - ${item.material_name}` : __("Add Material to BOM")}
            </h3>
            <form onSubmit={submit}>
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    {isEdit ? (
                        <div>
                            <label className="block text-sm font-medium text-om-muted mb-1">{__("Material")}</label>
                            <div className="form-input w-full bg-om-panel text-om-muted">
                                {item.material_code} - {item.material_name}
                            </div>
                        </div>
                    ) : (
                        <div>
                            <label className="block text-sm font-medium text-om-muted mb-1">
                                {__("Material")} <span className="text-om-blocked">*</span>
                            </label>
                            <Dropdown
                                value={data.material_id == null ? '' : String(data.material_id)}
                                onChange={(v) => onMaterialChange(v)}
                                placeholder="Select material..."
                                options={materials.map((m) => ({
                                    value: String(m.id),
                                    label: `${m.code} - ${m.name} (${m.unit_of_measure ? `${m.unit_of_measure}, ` : ''}${m.material_type_name})`,
                                }))}
                                className="w-full"
                            />
                            {errors.material_id && (
                                <p className="mt-1 text-sm text-om-blocked">{errors.material_id}</p>
                            )}
                        </div>
                    )}

                    <div className="md:col-span-2">
                        <label className="block text-sm font-medium text-om-muted mb-1">{__("Quantity rule")}</label>
                        <div className="inline-flex rounded border border-om-border p-1">
                            {[
                                ['per_unit', __('Per finished unit')],
                                ['ratio', __('Exact production ratio')],
                            ].map(([value, label]) => (
                                <button
                                    key={value}
                                    type="button"
                                    onClick={() => setQuantityRule(value)}
                                    className={`px-3 py-2 text-sm rounded ${data.quantity_rule === value ? 'bg-om-ink text-om-bg' : 'text-om-muted'}`}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>

                        {data.quantity_rule === 'per_unit' ? (
                            <div className="mt-3 max-w-sm">
                                <label className="block text-sm font-medium text-om-muted mb-1">
                                    {__("Quantity per Unit")}{unit ? ` (${unit})` : ''} <span className="text-om-blocked">*</span>
                                </label>
                                <input
                                    type="number"
                                    step="0.0001"
                                    min="0.0001"
                                    required
                                    value={data.quantity_per_unit}
                                    onChange={(e) => setData('quantity_per_unit', e.target.value)}
                                    className={`form-input w-full${errors.quantity_per_unit ? ' border-om-blocked' : ''}`}
                                />
                            </div>
                        ) : (
                            <div className="mt-3 grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-end gap-3 max-w-2xl">
                                <div>
                                    <label className="block text-sm font-medium text-om-muted mb-1">
                                        {__("Material quantity")}{unit ? ` (${unit})` : ''} <span className="text-om-blocked">*</span>
                                    </label>
                                    <input
                                        type="number"
                                        step="0.0001"
                                        min="0.0001"
                                        required
                                        value={data.component_quantity}
                                        onChange={(e) => setData('component_quantity', e.target.value)}
                                        className="form-input w-full"
                                    />
                                </div>
                                <span className="pb-3 text-om-faint">/</span>
                                <div>
                                    <label className="block text-sm font-medium text-om-muted mb-1">
                                        {__("Finished quantity")} <span className="text-om-blocked">*</span>
                                    </label>
                                    <input
                                        type="number"
                                        step="0.0001"
                                        min="0.0001"
                                        required
                                        value={data.output_quantity}
                                        onChange={(e) => setData('output_quantity', e.target.value)}
                                        className="form-input w-full"
                                    />
                                </div>
                            </div>
                        )}
                        <p className="mt-1 text-xs text-om-faint">
                            {data.quantity_rule === 'ratio'
                                ? __('Use an exact ratio for packaging and other discrete materials, for example 1 carton per 12 products.')
                                : __('How much of this material is needed per one finished product unit.')}
                        </p>
                        {(errors.quantity_per_unit || errors.component_quantity || errors.output_quantity) && (
                            <p className="mt-1 text-sm text-om-blocked">
                                {errors.quantity_per_unit || errors.component_quantity || errors.output_quantity}
                            </p>
                        )}
                    </div>
 
                    <div>
                        <label className="block text-sm font-medium text-om-muted mb-1">
                            {__("Step (optional)")}
                        </label>
                        <Dropdown
                            value={data.template_step_id == null ? '' : String(data.template_step_id)}
                            onChange={(v) => setData('template_step_id', v)}
                            options={[
                                { value: '', label: __('All steps / general') },
                                ...steps.map((s) => ({
                                    value: String(s.id),
                                    label: `#${s.step_number} - ${s.name}`,
                                })),
                            ]}
                            className="w-full"
                        />
                    </div>
 
                    <div>
                        <label className="block text-sm font-medium text-om-muted mb-1">{__("Scrap %")}</label>
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            max="100"
                            value={data.scrap_percentage}
                            onChange={(e) => setData('scrap_percentage', e.target.value)}
                            className="form-input w-full"
                        />
                        {selectedMaterial?.default_scrap_percentage != null && (
                            <p className="mt-1 text-xs text-om-faint">
                                {__("Pre-filled from the material default (:percentage%); adjust if needed.", { percentage: selectedMaterial.default_scrap_percentage })}
                            </p>
                        )}
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-om-muted mb-1">{__("Package rounding")}</label>
                        <Dropdown
                            value={data.rounding_mode}
                            onChange={(value) => setData('rounding_mode', value)}
                            options={[
                                { value: 'none', label: __('No rounding') },
                                { value: 'up', label: __('Round up') },
                                { value: 'down', label: __('Round down') },
                                { value: 'nearest', label: __('Round to nearest') },
                            ]}
                            className="w-full"
                        />
                    </div>

                    {data.rounding_mode !== 'none' && (
                        <div>
                            <label className="block text-sm font-medium text-om-muted mb-1">
                                {__("Package multiple")}{unit ? ` (${unit})` : ''}
                            </label>
                            <input
                                type="number"
                                step="0.0001"
                                min="0.0001"
                                required
                                value={data.rounding_multiple}
                                onChange={(e) => setData('rounding_multiple', e.target.value)}
                                className="form-input w-full"
                            />
                            <p className="mt-1 text-xs text-om-faint">
                                {__('Required quantity will be rounded to this multiple after scrap is applied.')}
                            </p>
                        </div>
                    )}

                    <div>
                        <label className="block text-sm font-medium text-om-muted mb-1">{__("Consumed At")}</label>
                        <Dropdown
                            value={data.consumed_at == null ? '' : String(data.consumed_at)}
                            onChange={(v) => setData('consumed_at', v)}
                            options={[
                                { value: 'start', label: __('Start of step') },
                                { value: 'during', label: __('During step') },
                                { value: 'end', label: __('End of step') },
                            ]}
                            className="w-full"
                        />
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-om-muted mb-1">{__("Notes")}</label>
                        <input
                            type="text"
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                            placeholder={__("Optional notes")}
                            className="form-input w-full"
                        />
                    </div>
                </div>

                <div className="flex justify-end gap-3 mt-4">
                    <button type="button" onClick={onCancel} className="btn-touch btn-secondary">
                        {__("Cancel")}
                    </button>
                    <button type="submit" disabled={processing} className="btn-touch btn-primary">
                        {isEdit
                            ? (processing ? __("Saving…") : __("Save Changes"))
                            : (processing ? __("Adding…") : __("Add to BOM"))}
                    </button>
                </div>
            </form>
        </div>
    );
}

export default function ProcessTemplatesBom() {
    const { productType, processTemplate, bomItems = [], materials = [], steps = [] } = usePage().props;

    const [showAddForm, setShowAddForm] = useState(false);
    const [editingItem, setEditingItem] = useState(null);

    const startEdit = (item) => {
        setShowAddForm(false);
        setEditingItem(item);
    };

    const handleRemove = (item) => {
        if (!confirm(__('Remove this material from BOM?'))) return;
        router.delete(
            `/admin/product-types/${productType.id}/process-templates/${processTemplate.id}/bom/${item.id}`,
            { preserveScroll: true },
        );
    };

    const columns = useMemo(() => [
        {
            id: 'material',
            accessorKey: 'material_name',
            header: __('Material'),
            cell: ({ row }) => (
                <>
                    <div className="text-sm font-medium text-om-ink">
                        {row.original.material_name}
                    </div>
                    <div className="text-xs text-om-muted font-mono">{row.original.material_code}</div>
                </>
            ),
        },
        {
            id: 'type',
            accessorKey: 'material_type_name',
            header: __('Type'),
            cell: ({ row }) => (
                <span
                    className={`px-2 py-1 rounded-full text-xs font-medium ${typeColorClass(
                        row.original.material_type_code,
                    )}`}
                >
                    {row.original.material_type_name}
                </span>
            ),
        },
        {
            id: 'step',
            accessorFn: (r) => (r.step_number != null ? `#${r.step_number} ${r.step_name}` : ''),
            header: __('Step'),
            cell: ({ row }) => (
                <span className="text-sm text-om-muted">
                    {row.original.step_number != null ? (
                        `#${row.original.step_number} ${row.original.step_name}`
                    ) : (
                        <span className="text-om-faint">{__("General")}</span>
                    )}
                </span>
            ),
        },
        {
            id: 'qty_per_unit',
            accessorKey: 'quantity_per_unit',
            header: __('Qty/Unit'),
            meta: { align: 'right' },
            cell: ({ row }) => (
                <span className="text-sm font-mono">
                    {formatQuantityRule(row.original)}
                </span>
            ),
        },
        {
            id: 'rounding',
            accessorKey: 'rounding_mode',
            header: __('Rounding'),
            cell: ({ row }) => (
                <span className="text-sm text-om-muted">
                    {formatRoundingRule(row.original, __)}
                </span>
            ),
        },
        {
            id: 'scrap',
            accessorKey: 'scrap_percentage',
            header: __('Scrap %'),
            meta: { align: 'right' },
            cell: ({ row }) => (
                <span className="text-sm">{row.original.scrap_percentage}%</span>
            ),
        },
        {
            id: 'consumed',
            accessorKey: 'consumed_at',
            header: 'Consumed',
            cell: ({ row }) => (
                <span className="text-sm text-om-muted capitalize">{row.original.consumed_at}</span>
            ),
        },
        {
            id: 'tracking',
            accessorKey: 'tracking_type',
            header: 'Tracking',
            cell: ({ row }) => (
                <span className="text-sm text-om-muted capitalize">{row.original.tracking_type}</span>
            ),
        },
        {
            id: 'actions',
            header: __('Actions'),
            enableSorting: false,
            meta: { align: 'right' },
            cell: ({ row }) => (
                <>
                    <button
                        type="button"
                        onClick={() => startEdit(row.original)}
                        className="text-om-accent hover:text-om-accent text-sm mr-4"
                    >
                        Edit
                    </button>
                    <button
                        type="button"
                        onClick={() => handleRemove(row.original)}
                        className="text-om-blocked hover:text-om-blocked text-sm"
                    >
                        Remove
                    </button>
                </>
            ),
        },
    ], [productType.id, processTemplate.id]);

    return (
        <>
            <Head title={`BOM - ${processTemplate.name}`} />

            <div className="max-w-7xl mx-auto">
                <div className="mb-6">
                    <a
                        href={`/admin/product-types/${productType.id}/process-templates/${processTemplate.id}`}
                        className="text-om-accent hover:text-om-accent flex items-center gap-2 mb-4"
                    >
                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 19l-7-7 7-7" />
                        </svg>
                        Back to Template
                    </a>

                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-3xl font-bold text-om-ink">{__("Bill of Materials")}</h1>
                            <p className="text-sm text-om-muted mt-1">
                                {processTemplate.name} (v{processTemplate.version}) &bull; {productType.name}
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={() => {
                                setEditingItem(null);
                                setShowAddForm((v) => !v);
                            }}
                            className="btn-touch btn-primary"
                        >
                            <svg className="w-5 h-5 inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                            </svg>
                            {__("Add Material")}
                        </button>
                    </div>
                </div>

                {showAddForm && !editingItem && (
                    <MaterialForm
                        productType={productType}
                        processTemplate={processTemplate}
                        materials={materials}
                        steps={steps}
                        onCancel={() => setShowAddForm(false)}
                    />
                )}

                {editingItem && (
                    <MaterialForm
                        key={editingItem.id}
                        productType={productType}
                        processTemplate={processTemplate}
                        materials={materials}
                        steps={steps}
                        item={editingItem}
                        onCancel={() => setEditingItem(null)}
                    />
                )}

                {bomItems.length > 0 ? (
                    <DataTable
                        data={bomItems}
                        columns={columns}
                        searchable={false}
                        columnToggle={false}
                        paginated={false}
                    />
                ) : (
                    <div className="card text-center py-12">
                        <p className="text-om-muted text-lg mb-4">{__("No materials in BOM yet.")}</p>
                        <button
                            type="button"
                            onClick={() => setShowAddForm(true)}
                            className="btn-touch btn-primary"
                        >
                            {__("Add First Material")}
                        </button>
                    </div>
                )}
            </div>
        </>
    );
}

ProcessTemplatesBom.layout = (page) => <AppLayout>{page}</AppLayout>;
