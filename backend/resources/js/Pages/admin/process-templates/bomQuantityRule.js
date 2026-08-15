export function hasExactQuantityRatio(item) {
    return item?.component_quantity != null && item?.output_quantity != null;
}

export function quantityRuleMode(item) {
    return hasExactQuantityRatio(item) ? 'ratio' : 'per_unit';
}

export function formatQuantityRule(item) {
    const unit = item?.unit_of_measure ? ` ${item.unit_of_measure}` : '';

    if (hasExactQuantityRatio(item)) {
        return `${item.component_quantity}${unit} / ${item.output_quantity}`;
    }

    return `${item?.quantity_per_unit ?? ''}${unit}`;
}

export function formatRoundingRule(item, translate = (value) => value) {
    const mode = item?.rounding_mode ?? 'none';
    if (mode === 'none') {
        return translate('None');
    }

    const labels = {
        up: 'Round up',
        down: 'Round down',
        nearest: 'Round to nearest',
    };

    return `${translate(labels[mode] ?? mode)} · ${item?.rounding_multiple ?? 1}`;
}
