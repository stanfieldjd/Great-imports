( function () {
    'use strict';

    var settings = window.giMultiDayCandidates || {};

    function dateParts( value ) {
        var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec( value || '' );

        if ( ! match ) {
            return null;
        }

        return {
            year: Number( match[1] ),
            month: Number( match[2] ),
            day: Number( match[3] )
        };
    }

    function timeParts( value ) {
        var match = /^(\d{2}):(\d{2})$/.exec( value || '' );

        if ( ! match ) {
            return null;
        }

        return {
            hour: Number( match[1] ),
            minute: Number( match[2] )
        };
    }

    function dateTimeValue( dateValue, timeValue ) {
        var date = dateParts( dateValue );
        var time = timeParts( timeValue );

        if ( ! date || ! time ) {
            return null;
        }

        return new Date( date.year, date.month - 1, date.day, time.hour, time.minute, 0, 0 );
    }

    function dateLabel( value ) {
        var date = dateParts( value );

        if ( ! date ) {
            return '';
        }

        return new Intl.DateTimeFormat(
            undefined,
            {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            }
        ).format( new Date( date.year, date.month - 1, date.day ) );
    }

    function timeLabel( value ) {
        var time = timeParts( value );

        if ( ! time ) {
            return '';
        }

        return new Intl.DateTimeFormat(
            undefined,
            {
                hour: 'numeric',
                minute: '2-digit'
            }
        ).format( new Date( 2000, 0, 1, time.hour, time.minute ) );
    }

    function windowLabel( fields ) {
        var startDateLabel = dateLabel( fields.startDate.value );
        var startTimeLabel = timeLabel( fields.startTime.value );
        var endDateLabel = dateLabel( fields.endDate.value );
        var endTimeLabel = timeLabel( fields.endTime.value );

        if ( ! startDateLabel ) {
            return settings.notSet || 'Not set';
        }

        if ( ! endDateLabel ) {
            return [ startDateLabel, startTimeLabel ].filter( Boolean ).join( ' ' );
        }

        if ( fields.startDate.value === fields.endDate.value ) {
            return [
                [ startDateLabel, startTimeLabel ].filter( Boolean ).join( ' ' ),
                endTimeLabel
            ].filter( Boolean ).join( ' – ' );
        }

        return [
            [ startDateLabel, startTimeLabel ].filter( Boolean ).join( ' ' ),
            [ endDateLabel, endTimeLabel ].filter( Boolean ).join( ' ' )
        ].join( ' – ' );
    }

    function fieldsForEditor( editor ) {
        return {
            startDate: editor.querySelector( 'input[name="start_date_date"]' ),
            startTime: editor.querySelector( 'input[name="start_date_time"]' ),
            endDate: editor.querySelector( 'input[name="end_date_date"]' ),
            endTime: editor.querySelector( 'input[name="end_date_time"]' )
        };
    }

    function hasAllFields( fields ) {
        return fields.startDate && fields.startTime && fields.endDate && fields.endTime;
    }

    function updateEditor( editor, fields ) {
        var summary = editor.querySelector( 'summary' );

        if ( ! summary || ! hasAllFields( fields ) ) {
            return;
        }

        fields.endDate.min = fields.startDate.value;
        fields.endDate.setCustomValidity( '' );
        fields.endTime.setCustomValidity( '' );
        summary.textContent = windowLabel( fields );

        if ( fields.startDate.value && fields.endDate.value && fields.startDate.value !== fields.endDate.value ) {
            editor.classList.add( 'gi-multi-day-event' );
        } else {
            editor.classList.remove( 'gi-multi-day-event' );
        }
    }

    function validateEditor( event, fields ) {
        var start = dateTimeValue( fields.startDate.value, fields.startTime.value );
        var end = dateTimeValue( fields.endDate.value, fields.endTime.value );
        var message = settings.invalidRange || 'The event end date and time must be after the start date and time.';

        fields.endDate.setCustomValidity( '' );
        fields.endTime.setCustomValidity( '' );

        if ( start && end && end.getTime() < start.getTime() ) {
            event.preventDefault();
            fields.endDate.setCustomValidity( message );
            fields.endDate.reportValidity();
        }
    }

    function prepareEditor( editor ) {
        var fields = fieldsForEditor( editor );
        var form = editor.querySelector( 'form' );

        if ( ! hasAllFields( fields ) || ! form ) {
            return;
        }

        [ fields.startDate, fields.startTime, fields.endDate, fields.endTime ].forEach(
            function ( field ) {
                field.addEventListener( 'input', function () {
                    updateEditor( editor, fields );
                } );
                field.addEventListener( 'change', function () {
                    updateEditor( editor, fields );
                } );
            }
        );

        form.addEventListener( 'submit', function ( event ) {
            validateEditor( event, fields );
        } );

        updateEditor( editor, fields );
    }

    document.querySelectorAll( '.gi-candidate-table .column-date .gi-inline-editor' ).forEach( prepareEditor );
}() );
