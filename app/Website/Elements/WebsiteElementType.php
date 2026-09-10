<?php

namespace App\Website\Elements;

enum WebsiteElementType: string
{
    case Heading = 'heading';
    case Text = 'text';
    case RichText = 'richText';
    case Date = 'date';
    case Accordion = 'accordion';
    case Schedule = 'schedule';
    case People = 'people';
    case Image = 'image';
    case Media = 'media';
    case Divider = 'divider';
    case Quote = 'quote';
    case Cta = 'cta';
    case MediaCollection = 'mediaCollection';
    case NarrativeBlock = 'narrativeBlock';
    case CompositionGroup = 'compositionGroup';
    case EventDate = 'eventDate';
    case EventTime = 'eventTime';
    case Countdown = 'countdown';
}
