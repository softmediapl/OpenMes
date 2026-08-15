import { useState } from 'react';
import { Checkbox } from '@openmes/ui';
import { __ } from '../../../lib/i18n';

const numericFields = [
    ['preferred_batch_quantity', 'Preferred batch quantity'],
    ['min_batch_quantity', 'Minimum regular batch quantity'],
    ['max_batch_quantity', 'Maximum regular batch quantity'],
    ['batch_quantity_multiple', 'Quantity increment'],
];

export default function BatchPolicyFields({ data, setData, errors = {} }) {
    const [enabled, setEnabled] = useState(data.preferred_batch_quantity !== '');

    const togglePolicy = (next) => {
        setEnabled(next);
        if (!next) {
            numericFields.forEach(([field]) => setData(field, ''));
        }
    };

    return (
        <section className="border-t border-om-line pt-5 mb-6">
            <h2 className="text-base font-semibold text-om-ink">{__('Production batch policy')}</h2>
            <p className="text-sm text-om-muted mt-1 mb-4">
                {__('Freeze the released batch size on each work order and create its batches when the order is accepted.')}
            </p>

            <Checkbox
                checked={enabled}
                onChange={togglePolicy}
                label={__('Create production batches automatically on acceptance')}
            />

            {enabled && (
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                    {numericFields.map(([field, label]) => (
                        <div key={field}>
                            <label htmlFor={field} className="form-label">{__(label)}</label>
                            <input
                                id={field}
                                type="number"
                                min="0.0001"
                                step="0.0001"
                                value={data[field]}
                                onChange={(event) => setData(field, event.target.value)}
                                className={`form-input w-full${errors[field] ? ' border-om-blocked' : ''}`}
                                required={field === 'preferred_batch_quantity'}
                            />
                            {errors[field] && <p className="text-om-blocked text-sm mt-1">{errors[field]}</p>}
                        </div>
                    ))}

                    <div className="sm:col-span-2">
                        <Checkbox
                            checked={data.allow_partial_final_batch}
                            onChange={(next) => setData('allow_partial_final_batch', next)}
                            label={__('Allow a smaller final batch')}
                        />
                        <p className="text-xs text-om-muted mt-1">
                            {__('Use the remaining released quantity for the last batch when the order is not exactly divisible.')}
                        </p>
                    </div>
                </div>
            )}
        </section>
    );
}
