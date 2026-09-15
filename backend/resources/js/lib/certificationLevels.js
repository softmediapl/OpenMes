import { __ } from './i18n';

const LABEL_KEYS = {
    trainee: 'Trainee',
    operator: 'Operator',
    expert: 'Expert',
    trainer: 'Trainer',
};

export function certificationLevelLabel(level) {
    return __(LABEL_KEYS[level] ?? level);
}
