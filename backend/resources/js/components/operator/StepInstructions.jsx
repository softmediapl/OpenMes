import React from 'react';
import { __ } from '../../lib/i18n';

function InstructionMedia({ item, onZoom, className = '' }) {
    if (item.media_type === 'image') {
        return (
            <button type="button" onClick={() => onZoom({ url: item.url, caption: item.title })} className={`block w-full cursor-pointer ${className}`}>
                <img src={item.url} alt={item.title || ''} loading="lazy" className="max-h-56 w-full rounded-om-sm border border-om-line bg-om-chip object-contain" />
            </button>
        );
    }

    if (item.media_type === 'video') {
        return <video src={item.url} controls preload="metadata" className={`max-h-72 w-full rounded-om-sm border border-om-line bg-black ${className}`} />;
    }

    if (item.media_type === 'pdf') {
        return (
            <div className={className}>
                <embed src={item.url} type="application/pdf" className="h-72 w-full rounded-om-sm border border-om-line bg-om-chip" />
                <a href={item.url} target="_blank" rel="noopener noreferrer" className="mt-1 inline-block text-[12px] text-om-accent hover:underline">
                    {__('Open PDF')}
                </a>
            </div>
        );
    }

    return null;
}

/** Render the complete operator-facing instruction before acknowledgement. */
export default function StepInstructions({ instruction, media = [], photo = null, panel = false, onZoom = () => {} }) {
    const referenceMedia = photo
        ? [{ id: `photo-${photo.id ?? photo.url}`, media_type: 'image', url: photo.url, title: photo.caption }]
        : [];
    const allMedia = [...referenceMedia, ...media.filter((item) => !photo || item.url !== photo.url)];

    if (panel) {
        return (
            <div className={`panel-instruction-reference ${allMedia.length === 0 ? 'panel-instruction-reference-single' : ''}`} data-panel-instructions>
                <div className="panel-instruction-copy">
                    <p className="panel-label">{__('Work instruction')}</p>
                    <p className="whitespace-pre-wrap text-base leading-relaxed text-om-ink">{instruction?.trim() || '—'}</p>
                </div>
                {allMedia.length > 0 && (
                    <div className="panel-instruction-media">
                        <p className="panel-label">{__('Reference image')}</p>
                        <div className="space-y-3">
                            {allMedia.map((item) => (
                                <div key={item.id}>
                                    {item.title && <p className="mb-1 text-[12px] font-medium text-om-muted">{item.title}</p>}
                                    <InstructionMedia item={item} onZoom={onZoom} />
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        );
    }

    return (
        <div className="border-t border-om-line2 px-3 py-2 space-y-3">
            {instruction?.trim() && (
                <div>
                    <p className="text-[12px] font-semibold text-om-muted mb-1">{__('Work instruction')}</p>
                    <p className="text-sm text-om-ink whitespace-pre-wrap">{instruction}</p>
                </div>
            )}
            {media.map((item) => (
                <div key={item.id}>
                    {item.title && <p className="text-[12px] font-medium text-om-muted mb-1">{item.title}</p>}
                    <InstructionMedia item={item} onZoom={onZoom} />
                </div>
            ))}
        </div>
    );
}
