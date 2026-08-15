import { compactQuantity } from './configuredQuantity';

export function hasExactQuantityRatio(item) {
    return item?.component_quantity != null && item?.output_quantity != null;
}

export function quantityRuleMode(item) {
    return hasExactQuantityRatio(item) ? 'ratio' : 'per_unit';
}

export function formatQuantityRule(item, materialPrecision, outputPrecision) {
    const configuredMaterialPrecision = materialPrecision ?? item?.quantity_precision;
    const configuredOutputPrecision = outputPrecision ?? item?.output_quantity_precision;
    const unit = item?.unit_of_measure ? ` ${item.unit_of_measure}` : '';

    if (hasExactQuantityRatio(item)) {
        return `${compactQuantity(item.component_quantity, configuredMaterialPrecision, item?.unit_of_measure)}${unit} / ${compactQuantity(item.output_quantity, configuredOutputPrecision, item?.output_unit_of_measure)}`;
    }

    return `${compactQuantity(item?.quantity_per_unit, configuredMaterialPrecision, item?.unit_of_measure)}${unit}`;
}

export function formatRoundingRule(item, precision, translate = (value) => value) {
    const configuredPrecision = precision ?? item?.quantity_precision;
    const mode = item?.rounding_mode ?? 'none';
    if (mode === 'none') {
        return translate('None');
    }

    const labels = {
        up: 'Round up',
        down: 'Round down',
        nearest: 'Round to nearest',
    };

    return `${translate(labels[mode] ?? mode)} · ${compactQuantity(item?.rounding_multiple ?? 1, configuredPrecision, item?.unit_of_measure)}`;
}
