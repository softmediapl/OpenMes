import { Ban, Check, Circle, Clock3, Play } from 'lucide-react';
import { __, formatNumber } from '../lib/i18n';
import { buildProductionRoute } from '../lib/productionRoute';

const STATUS = {
    DONE: { label: 'Done', icon: Check, dot: 'bg-om-running text-white', line: 'bg-om-running' },
    SKIPPED: { label: 'Skipped', icon: Ban, dot: 'bg-om-chip text-om-muted', line: 'bg-om-line' },
    IN_PROGRESS: { label: 'In progress', icon: Play, dot: 'bg-om-accent text-white', line: 'bg-om-accent' },
    READY: { label: 'Ready', icon: Clock3, dot: 'bg-om-selected text-om-accent', line: 'bg-om-line' },
    BLOCKED: { label: 'Blocked', icon: Ban, dot: 'bg-om-blocked text-white', line: 'bg-om-blocked' },
    PENDING: { label: 'Pending', icon: Circle, dot: 'bg-om-card text-om-faint border border-om-line', line: 'bg-om-line2' },
};

function qty(value) {
    return value == null ? null : formatNumber(value, { maximumFractionDigits: 2 });
}

export default function ProductionRoute({ processSnapshot, batches = [] }) {
    const operations = buildProductionRoute(processSnapshot, batches);
    if (!operations.length) return null;

    return (
        <section className="bg-om-card border border-om-line rounded-om p-5">
            <div className="flex flex-wrap items-end justify-between gap-2 mb-5">
                <div>
                    <h2 className="text-[14px] font-semibold text-om-ink">{__('Production route')}</h2>
                    <p className="text-xs text-om-muted mt-1">{__('Live operation status and reported yield across all batches.')}</p>
                </div>
                {processSnapshot?.composition && (
                    <span className="font-mono text-[9.5px] uppercase text-om-faint">
                        {__('Composed from :name v:version', {
                            name: processSnapshot.composition.base_template_name,
                            version: processSnapshot.composition.base_template_version,
                        })}
                    </span>
                )}
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-x-8">
                {operations.map((operation, index) => {
                    const config = STATUS[operation.status] ?? STATUS.PENDING;
                    const Icon = config.icon;
                    const isLast = index === operations.length - 1;
                    const yieldReported = operation.goodQuantity != null || operation.scrapQuantity != null || operation.reworkQuantity != null;

                    return (
                        <div key={operation.operationCode ?? operation.stepNumber} className="relative flex gap-3 min-h-[76px]">
                            <div className="relative flex flex-col items-center flex-shrink-0">
                                <span className={`w-7 h-7 rounded-full flex items-center justify-center z-[1] ${config.dot}`}>
                                    <Icon size={14} strokeWidth={2.2} />
                                </span>
                                {!isLast && <span className={`w-px flex-1 ${config.line}`} />}
                            </div>
                            <div className="min-w-0 pb-5 flex-1">
                                <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                    <div className="min-w-0">
                                        <span className="font-mono text-[9.5px] text-om-faint mr-2">{String(operation.stepNumber).padStart(2, '0')}</span>
                                        <span className="text-sm font-semibold text-om-ink">{operation.name}</span>
                                    </div>
                                    <span className="font-mono text-[9.5px] uppercase text-om-muted">{__(config.label)}</span>
                                </div>
                                <div className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-om-muted">
                                    {operation.workstationName && <span>{operation.workstationName}</span>}
                                    {yieldReported && operation.goodQuantity != null && <span>{__('Good')}: <strong className="text-om-running">{qty(operation.goodQuantity)}</strong></span>}
                                    {yieldReported && operation.scrapQuantity != null && <span>{__('Scrap')}: <strong className="text-om-blocked">{qty(operation.scrapQuantity)}</strong></span>}
                                    {yieldReported && operation.reworkQuantity != null && <span>{__('Rework')}: <strong className="text-om-accent">{qty(operation.reworkQuantity)}</strong></span>}
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>
        </section>
    );
}
