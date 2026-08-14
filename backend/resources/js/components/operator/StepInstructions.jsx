import React from 'react';
import { __ } from '../../lib/i18n';

/** Render the complete operator-facing instruction before acknowledgement. */
export default function StepInstructions({ instruction, media = [], onZoom = () => {} }) {
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
                    {item.media_type === 'image' && (
                        <button type="button" onClick={() => onZoom({ url: item.url, caption: item.title })} className="block cursor-pointer">
                            <img src={item.url} alt={item.title || ''} loading="lazy" className="max-h-56 rounded-om-sm border border-om-line bg-om-chip object-contain" />
                        </button>
                    )}
                    {item.media_type === 'video' && (
                        <video src={item.url} controls preload="metadata" className="w-full max-h-72 rounded-om-sm border border-om-line bg-black" />
                    )}
                    {item.media_type === 'pdf' && (
                        <div>
                            <embed src={item.url} type="application/pdf" className="w-full h-72 rounded-om-sm border border-om-line bg-om-chip" />
                            <a href={item.url} target="_blank" rel="noopener noreferrer" className="inline-block mt-1 text-[12px] text-om-accent hover:underline">
                                {__('Open PDF')}
                            </a>
                        </div>
                    )}
                </div>
            ))}
        </div>
    );
}
