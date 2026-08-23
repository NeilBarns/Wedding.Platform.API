<?php

namespace App\Website\Elements;

enum WebsiteElementType: string
{
    case Heading = 'heading';
    case Text = 'text';
    case Image = 'image';
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
