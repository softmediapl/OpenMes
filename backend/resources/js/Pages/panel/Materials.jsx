import OperatorMaterials from '../operator/Materials';
import PanelLayout from '../../layouts/PanelLayout';

export default function Materials(props) {
    return <OperatorMaterials {...props} />;
}

Materials.layout = (page) => <PanelLayout>{page}</PanelLayout>;
