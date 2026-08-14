import { useForm } from '@inertiajs/react';
import { Dropdown } from '@openmes/ui';
import { __ } from '../../../lib/i18n';
import { processDependencyPayload, validateProcessDependencies } from './processDependencies';

const validationMessages = {
    incomplete: 'Select both operations for every dependency.',
    self: 'An operation cannot depend on itself.',
    duplicate: 'The same dependency has been added more than once.',
    cycle: 'Operation dependencies must not contain a cycle.',
};

export default function ProcessDependenciesEditor({ productType, processTemplate }) {
    const steps = processTemplate.steps ?? [];
    const form = useForm({
        dependency_mode: processTemplate.dependency_mode ?? 'sequential',
        dependencies: (processTemplate.dependencies ?? []).map((dependency) => ({
            predecessor_step_id: Number(dependency.predecessor_step_id),
            successor_step_id: Number(dependency.successor_step_id),
            lag_minutes: Number(dependency.lag_minutes ?? 0),
        })),
    });
    const explicit = form.data.dependency_mode === 'explicit';
    const validationError = explicit
        ? validateProcessDependencies(steps, form.data.dependencies)
        : null;
    const options = steps.map((step) => ({
        value: String(step.id),
        label: `${step.step_number}. ${step.name}`,
    }));

    const setMode = (mode) => form.setData('dependency_mode', mode);
    const updateDependency = (index, field, value) => {
        const next = [...form.data.dependencies];
        next[index] = {
            ...next[index],
            [field]: field === 'lag_minutes' ? value : Number(value),
        };
        form.setData('dependencies', next);
    };
    const addDependency = () => {
        if (steps.length < 2) return;
        form.setData('dependencies', [
            ...form.data.dependencies,
            {
                predecessor_step_id: Number(steps[0].id),
                successor_step_id: Number(steps[1].id),
                lag_minutes: 0,
            },
        ]);
    };
    const removeDependency = (index) => {
        form.setData(
            'dependencies',
            form.data.dependencies.filter((_, dependencyIndex) => dependencyIndex !== index),
        );
    };
    const submit = (event) => {
        event.preventDefault();
        if (validationError) return;

        form.transform(() =>
            processDependencyPayload(form.data.dependency_mode, form.data.dependencies),
        ).put(
            `/admin/product-types/${productType.id}/process-templates/${processTemplate.id}/step-dependencies`,
            { preserveScroll: true },
        );
    };

    return (
        <section className="card mb-6" aria-labelledby="process-dependencies-heading">
            <div className="flex flex-wrap items-start justify-between gap-4 mb-4">
                <div>
                    <h2 id="process-dependencies-heading" className="text-xl font-bold text-om-ink">
                        {__('Operation flow')}
                    </h2>
                    <p className="text-sm text-om-muted mt-1">
                        {__('Define which operations must finish before another operation can start.')}
                    </p>
                </div>

                <div className="inline-flex rounded-om-sm border border-om-line p-1 bg-om-panel">
                    {[
                        ['sequential', 'Sequential'],
                        ['explicit', 'Dependency graph'],
                    ].map(([mode, label]) => (
                        <button
                            key={mode}
                            type="button"
                            onClick={() => setMode(mode)}
                            className={`px-3 py-2 text-sm font-medium rounded-om-sm ${
                                form.data.dependency_mode === mode
                                    ? 'bg-om-ink text-om-on-ink'
                                    : 'text-om-muted hover:text-om-ink'
                            }`}
                        >
                            {__(label)}
                        </button>
                    ))}
                </div>
            </div>

            {explicit ? (
                <form onSubmit={submit}>
                    <div className="border-y border-om-line">
                        {form.data.dependencies.length === 0 ? (
                            <p className="py-5 text-sm text-om-muted">
                                {__('No dependencies. Every operation can start independently.')}
                            </p>
                        ) : (
                            form.data.dependencies.map((dependency, index) => (
                                <div
                                    key={`${index}-${dependency.predecessor_step_id}-${dependency.successor_step_id}`}
                                    className="grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)_9rem_auto] items-end gap-3 py-3 border-b border-om-line last:border-b-0"
                                >
                                    <div>
                                        <label className="form-label">{__('Predecessor')}</label>
                                        <Dropdown
                                            value={String(dependency.predecessor_step_id)}
                                            onChange={(value) =>
                                                updateDependency(index, 'predecessor_step_id', value)
                                            }
                                            options={options}
                                            className="w-full"
                                        />
                                    </div>
                                    <span className="hidden md:block pb-3 text-om-muted" aria-hidden="true">
                                        →
                                    </span>
                                    <div>
                                        <label className="form-label">{__('Successor')}</label>
                                        <Dropdown
                                            value={String(dependency.successor_step_id)}
                                            onChange={(value) =>
                                                updateDependency(index, 'successor_step_id', value)
                                            }
                                            options={options}
                                            className="w-full"
                                        />
                                    </div>
                                    <div>
                                        <label className="form-label">{__('Lag (minutes)')}</label>
                                        <input
                                            type="number"
                                            min="0"
                                            max="525600"
                                            value={dependency.lag_minutes}
                                            onChange={(event) =>
                                                updateDependency(index, 'lag_minutes', event.target.value)
                                            }
                                            className="form-input w-full"
                                        />
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => removeDependency(index)}
                                        className="btn-touch btn-secondary"
                                        title={__('Remove dependency')}
                                        aria-label={__('Remove dependency')}
                                    >
                                        ×
                                    </button>
                                </div>
                            ))
                        )}
                    </div>

                    {(validationError || form.errors.dependencies || form.errors.dependency_mode) && (
                        <p className="text-sm text-om-blocked mt-3" role="alert">
                            {__(validationMessages[validationError] ?? form.errors.dependencies ?? form.errors.dependency_mode)}
                        </p>
                    )}

                    <div className="flex flex-wrap justify-between gap-3 mt-4">
                        <button
                            type="button"
                            onClick={addDependency}
                            disabled={steps.length < 2}
                            className="btn-touch btn-secondary disabled:opacity-50"
                        >
                            {__('Add dependency')}
                        </button>
                        <button
                            type="submit"
                            disabled={form.processing || !!validationError}
                            className="btn-touch btn-primary disabled:opacity-50"
                        >
                            {form.processing ? __('Saving…') : __('Save operation flow')}
                        </button>
                    </div>
                </form>
            ) : (
                <form onSubmit={submit}>
                    <p className="text-sm text-om-muted border-y border-om-line py-4">
                        {__('Operations run one after another in the displayed step order.')}
                    </p>
                    <div className="flex justify-end mt-4">
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="btn-touch btn-primary disabled:opacity-50"
                        >
                            {form.processing ? __('Saving…') : __('Save operation flow')}
                        </button>
                    </div>
                </form>
            )}
        </section>
    );
}
