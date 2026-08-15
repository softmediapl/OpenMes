import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
// Explicit extension: `components/engineeringDocuments.js` differs only in case,
// so an extensionless import resolves to the wrong file on case-insensitive
// filesystems and breaks the build.
import EngineeringDocuments from '../../../components/EngineeringDocuments.jsx';
import { __, formatDate } from '../../../lib/i18n';
import { LIFECYCLE_BADGE_STYLES, lifecycleLabel } from './fields';

export default function ProductRevisionShow({ productRevision }) {
    const status = productRevision.lifecycle_status;

    return (
        <>
            <Head title={`${__('Product Revision')} ${productRevision.revision_code}`} />

            <nav className="text-sm text-om-muted mb-4">
                <Link href="/admin/dashboard" className="hover:text-om-ink">{__('Dashboard')}</Link>
                {' / '}
                <Link href="/admin/product-revisions" className="hover:text-om-ink">{__('Product Revisions')}</Link>
                {' / '}
                <span className="text-om-ink">{productRevision.revision_code}</span>
            </nav>

            <div className="flex items-center gap-3 mb-6">
                <h1 className="text-3xl font-bold text-om-ink">{productRevision.revision_code}</h1>
                <span className={`inline-block rounded px-2 py-0.5 text-xs ${LIFECYCLE_BADGE_STYLES[status] ?? 'bg-gray-200 text-gray-700'}`}>
                    {lifecycleLabel(status)}
                </span>
            </div>

            <div className="card max-w-2xl">
                <h2 className="text-lg font-semibold text-om-ink mb-3">{__('Details')}</h2>
                <dl className="space-y-2">
                    <Row label={__('Product type')} value={productRevision.product_type
                        ? `${productRevision.product_type.name} (${productRevision.product_type.code})` : '—'} />
                    <Row label={__('Effective from')} value={productRevision.effective_from ? formatDate(productRevision.effective_from) : '—'} />
                    <Row label={__('Effective to')} value={productRevision.effective_to ? formatDate(productRevision.effective_to) : '—'} />
                    <Row label={__('External reference')} value={productRevision.external_ref || '—'} />
                    {productRevision.description && (
                        <div className="pt-2">
                            <dt className="text-sm text-om-muted mb-1">{__('Description')}</dt>
                            <dd className="text-sm">{productRevision.description}</dd>
                        </div>
                    )}
                </dl>
            </div>

            <div className="card max-w-2xl mt-4">
                <h2 className="text-lg font-semibold text-om-ink mb-3">{__('Process templates')}</h2>
                {productRevision.process_templates?.length ? (
                    <div className="space-y-2">
                        {productRevision.process_templates.map((template) => (
                            <div key={template.id} className="flex items-center justify-between gap-4 rounded-om-sm bg-om-panel px-3 py-2">
                                <span className="text-sm font-medium text-om-ink">{template.name} v{template.version}</span>
                                <span className={`text-xs px-2 py-0.5 rounded font-medium ${template.is_active ? 'bg-om-running-bg text-om-running' : 'bg-om-chip text-om-muted'}`}>
                                    {template.is_active ? __('Active') : __('Inactive')}
                                </span>
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="text-sm text-om-muted">{__('No process templates are assigned to this revision yet.')}</p>
                )}
            </div>

            <EngineeringDocuments entityType="product_revision" entityId={productRevision.id} />
            <p className="text-xs text-om-muted mt-2 max-w-2xl">
                {__('A complete assembly keeps its engineering documents here, on its product type or product revision.')}
            </p>
        </>
    );
}

ProductRevisionShow.layout = (page) => <AppLayout>{page}</AppLayout>;

function Row({ label, value }) {
    return (
        <div className="flex justify-between gap-4">
            <dt className="text-sm text-om-muted">{label}</dt>
            <dd className="text-sm font-medium text-right">{value}</dd>
        </div>
    );
}
