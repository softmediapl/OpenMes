import { Head, Link, useForm } from '@inertiajs/react';
import { Button, Dropdown, TextField } from '@openmes/ui';
import OnboardingLayout from '../../layouts/OnboardingLayout';
import { __ } from '../../lib/i18n';

/**
 * Onboarding Step 2 — Add a Product Type.
 * POST /onboarding/step/2 → OnboardingController@storeStep2
 *
 * Geist White restyle: light-only v1 — om-* tokens, @openmes/ui controls.
 */
export default function Step2({ unitsOfMeasure = [] }) {
    const form = useForm({ name: '', code: '', unit_of_measure: '' });
    const { data, setData, post, processing, errors } = form;

    const submit = (e) => {
        e.preventDefault();
        post('/onboarding/step/2');
    };

    return (
        <>
            <Head title={__('Step 2 — Product Type')} />
            <div className="font-mono text-[9.5px] uppercase tracking-[0.08em] text-om-faint mb-2">Step 3/5</div>
            <h2 className="text-xl font-semibold tracking-[-0.02em] text-om-ink mb-2">{__('Add a Product Type')}</h2>
            <p className="text-sm text-om-muted mb-6">{__('What product does this line produce? Define the product type.')}</p>

            <form onSubmit={submit}>
                <div className="space-y-4">
                    <TextField
                        label={<>{__('Product Name')} <span className="text-om-accent">*</span></>}
                        id="name"
                        value={data.name}
                        onChange={(v) => setData('name', v)}
                        error={errors.name}
                        required
                        placeholder={__('e.g. Air Filter')}
                    />

                    <TextField
                        label={<>{__('Code')} <span className="text-om-accent">*</span></>}
                        id="code"
                        value={data.code}
                        onChange={(v) => setData('code', v)}
                        error={errors.code}
                        required
                        placeholder={__('e.g. FILTER')}
                    />

                    <div>
                        <label className="mb-1 block text-[12.5px] font-medium text-om-ink">
                            {__('Unit of Measure')} <span className="text-om-accent">*</span>
                        </label>
                        <Dropdown
                        value={data.unit_of_measure}
                            onChange={(v) => setData('unit_of_measure', v)}
                            placeholder={__('Select unit of measure…')}
                            options={unitsOfMeasure.map((unit) => ({
                                value: unit.code,
                                label: `${unit.name} (${unit.symbol || unit.code})`,
                            }))}
                        />
                        {errors.unit_of_measure && <p className="mt-1 text-sm text-om-blocked">{errors.unit_of_measure}</p>}
                    </div>
                </div>

                <div className="flex items-center justify-between mt-6">
                    <Link href="/onboarding/step/1" className="text-om-muted hover:text-om-ink text-[13px] transition-colors">
                        ← Back
                    </Link>
                    <Button type="submit" variant="accent" loading={processing}>
                        {processing ? __('Saving…') : __('Next: Process Template →')}
                    </Button>
                </div>
            </form>
        </>
    );
}

Step2.layout = (page) => <OnboardingLayout>{page}</OnboardingLayout>;
