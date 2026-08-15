import { __ } from '../../../lib/i18n';

export default function PackagingPolicyFields({ data, setData, errors = {} }) {
    return (
        <section className="border-t border-om-line pt-5 mb-6">
            <h2 className="text-base font-semibold text-om-ink">{__('Packaging policy')}</h2>
            <p className="text-sm text-om-muted mt-1 mb-4">
                {__('Freeze the pallet capacity on each released work order.')}
            </p>

            <label htmlFor="pallet_capacity_quantity" className="form-label">
                {__('Pallet capacity')}
            </label>
            <input
                id="pallet_capacity_quantity"
                type="number"
                min="1"
                step="1"
                value={data.pallet_capacity_quantity}
                onChange={(event) => setData('pallet_capacity_quantity', event.target.value)}
                className={`form-input w-full${errors.pallet_capacity_quantity ? ' border-om-blocked' : ''}`}
            />
            <p className="text-xs text-om-muted mt-1">
                {__('Maximum number of finished units on one pallet. Leave blank for no configured limit.')}
            </p>
            {errors.pallet_capacity_quantity && (
                <p className="text-om-blocked text-sm mt-1">{errors.pallet_capacity_quantity}</p>
            )}
        </section>
    );
}
